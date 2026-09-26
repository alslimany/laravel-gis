<?php

namespace App\Http\Controllers;

use App\Helpers\GeometryColumnHelper;
use App\Helpers\QueryBuilder;
use App\Helpers\SpatialHelper;
use App\Models\AnalysisResult;
use App\Models\Layer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnalysisController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Perform buffer analysis on a geometry.
     */
    public function buffer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wkt' => 'required|string',
            'distance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $buffered = SpatialHelper::buffer($request->wkt, $request->distance);
            $bufferedGeoJson = SpatialHelper::wktToGeoJson($buffered);

            return response()->json([
                'success' => true,
                'buffered_wkt' => $buffered,
                'buffered_geojson' => $bufferedGeoJson,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Perform spatial query (within, contains, intersects).
     */
    public function spatialQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'layer_id' => 'required|exists:layers,id',
            'operation' => 'required|in:within,contains,intersects',
            'wkt' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $layer = Layer::findOrFail($request->layer_id);

        // Check authorization
        if ($layer->organization_id !== auth()->user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);
            $results = match ($request->operation) {
                'within' => QueryBuilder::within($layer->table_name, $geometryColumn, $request->wkt),
                'contains' => QueryBuilder::contains($layer->table_name, $geometryColumn, $request->wkt),
                'intersects' => QueryBuilder::intersects($layer->table_name, $geometryColumn, $request->wkt),
            };

            return response()->json([
                'success' => true,
                'count' => $results->count(),
                'features' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Perform attribute query on a layer.
     */
    public function attributeQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'layer_id' => 'required|exists:layers,id',
            'conditions' => 'required|array',
            'conditions.*.column' => 'required|string',
            'conditions.*.operator' => 'required|string',
            'conditions.*.value' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $layer = Layer::findOrFail($request->layer_id);

        // Check authorization
        if ($layer->organization_id !== auth()->user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);
            $results = QueryBuilder::attributeQuery(
                $layer->table_name,
                $request->conditions,
                $geometryColumn
            );

            return response()->json([
                'success' => true,
                'count' => $results->count(),
                'features' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate distance between two points.
     */
    public function measureDistance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat1' => 'required|numeric',
            'lon1' => 'required|numeric',
            'lat2' => 'required|numeric',
            'lon2' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $distance = SpatialHelper::distance(
                $request->lat1,
                $request->lon1,
                $request->lat2,
                $request->lon2
            );

            return response()->json([
                'success' => true,
                'distance_meters' => $distance,
                'distance_km' => round($distance / 1000, 2),
                'distance_miles' => round($distance / 1609.34, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate area of a polygon.
     */
    public function measureArea(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wkt' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $area = SpatialHelper::area($request->wkt);

            return response()->json([
                'success' => true,
                'area_sqm' => $area,
                'area_sqkm' => round($area / 1000000, 4),
                'area_hectares' => round($area / 10000, 4),
                'area_acres' => round($area / 4046.86, 4),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save a query or analysis result so a dashboard widget can use it.
     * Saving is optional. The result stores feature ids, not geometries.
     */
    public function storeResult(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'layer_id' => 'nullable|integer',
            'kind' => 'required|in:spatial_query,attribute_query,layer_buffer,overlay,query',
            'feature_ids' => 'nullable|array|max:2000',
            'feature_ids.*' => 'integer|min:1',
            'feature_count' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $layer = null;
        if ($request->filled('layer_id')) {
            $layer = Layer::query()->find($request->integer('layer_id'));
            if (! $layer || (int) $layer->organization_id !== (int) auth()->user()->organization_id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        $sentIds = is_array($request->input('feature_ids'));
        $ids = collect($request->input('feature_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($sentIds && $ids->isNotEmpty()) {
            if (! $layer || ! $layer->table_name || ! Schema::hasTable($layer->table_name) || ! Schema::hasColumn($layer->table_name, 'id')) {
                return response()->json(['error' => 'Layer or table not found.'], 422);
            }

            $ids = DB::table($layer->table_name)
                ->whereIn('id', $ids->all())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $result = AnalysisResult::create([
            'organization_id' => auth()->user()->organization_id,
            'user_id' => auth()->id(),
            'layer_id' => $layer?->id,
            'name' => (string) $request->input('name'),
            'kind' => (string) $request->input('kind'),
            'feature_count' => $sentIds ? $ids->count() : (int) $request->input('feature_count', 0),
            'feature_ids' => $ids->isNotEmpty() ? $ids->all() : null,
        ]);

        return response()->json([
            'success' => true,
            'analysis' => [
                'id' => $result->id,
                'name' => $result->name,
                'kind' => $result->kind,
                'layer_id' => $result->layer_id,
                'feature_count' => $result->feature_count,
            ],
        ]);
    }

    /**
     * Perform buffer analysis on layer features.
     */
    public function layerBuffer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'layer_id' => 'required|exists:layers,id',
            'distance' => 'required|numeric|min:0',
            'conditions' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $layer = Layer::findOrFail($request->layer_id);

        // Check authorization
        if ($layer->organization_id !== auth()->user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);
            $results = QueryBuilder::bufferAnalysis(
                $layer->table_name,
                $geometryColumn,
                $request->distance,
                $request->conditions ?? []
            );

            return response()->json([
                'success' => true,
                'count' => $results->count(),
                'features' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Union overlay of two layers (ST_Union).
     */
    public function union(Request $request)
    {
        return $this->overlay($request, 'union');
    }

    /**
     * Intersection overlay of two layers (ST_Intersection).
     */
    public function intersect(Request $request)
    {
        return $this->overlay($request, 'intersect');
    }

    /**
     * Erase overlay: geometries in A minus B (ST_Difference).
     */
    public function erase(Request $request)
    {
        return $this->overlay($request, 'erase');
    }

    /**
     * Shared overlay analysis using PostGIS.
     *
     * @param  'union'|'intersect'|'erase'  $operation
     */
    protected function overlay(Request $request, string $operation)
    {
        $validator = Validator::make($request->all(), [
            'layer_id_a' => 'required|exists:layers,id',
            'layer_id_b' => 'required|exists:layers,id',
            'write_result' => 'sometimes|boolean',
            'result_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $layerA = Layer::findOrFail($request->layer_id_a);
        $layerB = Layer::findOrFail($request->layer_id_b);
        $orgId = Auth::user()->organization_id;

        if ($layerA->organization_id !== $orgId || $layerB->organization_id !== $orgId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $geomA = GeometryColumnHelper::resolve($layerA->table_name);
            $geomB = GeometryColumnHelper::resolve($layerB->table_name);
            $tableA = $layerA->table_name;
            $tableB = $layerB->table_name;

            $geomExpr = match ($operation) {
                'union' => "ST_Union(a.{$geomA}, b.{$geomB})",
                'intersect' => "ST_Intersection(a.{$geomA}, b.{$geomB})",
                'erase' => "ST_Difference(a.{$geomA}, b.{$geomB})",
            };

            // Pairwise overlay where geometries intersect (erase still needs candidates that touch).
            $sql = "
                SELECT jsonb_build_object(
                    'type', 'FeatureCollection',
                    'features', COALESCE(jsonb_agg(feature), '[]'::jsonb)
                ) AS geojson
                FROM (
                    SELECT jsonb_build_object(
                        'type', 'Feature',
                        'geometry', ST_AsGeoJSON(ST_MakeValid(result_geom))::jsonb,
                        'properties', jsonb_build_object(
                            'layer_a_id', a.id,
                            'layer_b_id', b.id,
                            'operation', ?
                        )
                    ) AS feature
                    FROM {$tableA} a
                    CROSS JOIN {$tableB} b
                    CROSS JOIN LATERAL (
                        SELECT {$geomExpr} AS result_geom
                    ) r
                    WHERE ST_Intersects(a.{$geomA}, b.{$geomB})
                      AND r.result_geom IS NOT NULL
                      AND NOT ST_IsEmpty(r.result_geom)
                ) features
            ";

            $result = DB::selectOne($sql, [$operation]);
            $geojson = json_decode($result->geojson ?? '{"type":"FeatureCollection","features":[]}', true);
            $featureCount = count($geojson['features'] ?? []);

            $resultLayer = null;
            if ($request->boolean('write_result') && $featureCount > 0) {
                $resultLayer = $this->writeOverlayResult(
                    $operation,
                    $layerA,
                    $layerB,
                    $geojson,
                    $request->input('result_name')
                );
            }

            return response()->json([
                'success' => true,
                'operation' => $operation,
                'count' => $featureCount,
                'geojson' => $geojson,
                'result_layer' => $resultLayer,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Persist overlay GeoJSON into a new PostGIS table + Layer record.
     *
     * @param  array<string, mixed>  $geojson
     */
    protected function writeOverlayResult(
        string $operation,
        Layer $layerA,
        Layer $layerB,
        array $geojson,
        ?string $resultName = null
    ): Layer {
        $safeOp = preg_replace('/[^a-z]/', '', $operation) ?: 'overlay';
        $tableName = 'overlay_'.$safeOp.'_'.Str::lower(Str::random(8));
        $name = $resultName ?: ucfirst($operation).' of '.$layerA->name.' & '.$layerB->name;

        DB::statement("
            CREATE TABLE {$tableName} (
                id SERIAL PRIMARY KEY,
                layer_a_id INTEGER,
                layer_b_id INTEGER,
                operation VARCHAR(32),
                geom geometry(Geometry, 4326)
            )
        ");

        foreach ($geojson['features'] ?? [] as $feature) {
            $geometry = json_encode($feature['geometry'] ?? null);
            if (! $geometry || $geometry === 'null') {
                continue;
            }

            DB::table($tableName)->insert([
                'layer_a_id' => $feature['properties']['layer_a_id'] ?? $layerA->id,
                'layer_b_id' => $feature['properties']['layer_b_id'] ?? $layerB->id,
                'operation' => $operation,
                'geom' => DB::raw('ST_SetSRID(ST_GeomFromGeoJSON('.DB::getPdo()->quote($geometry).'), 4326)'),
            ]);
        }

        DB::statement("CREATE INDEX ON {$tableName} USING GIST (geom)");

        $count = (int) DB::table($tableName)->count();

        return Layer::create([
            'project_id' => $layerA->project_id,
            'user_id' => Auth::id(),
            'organization_id' => Auth::user()->organization_id,
            'name' => $name,
            'description' => "Overlay {$operation} result from layers {$layerA->id} and {$layerB->id}",
            'table_name' => $tableName,
            'geometry_type' => 'GEOMETRY',
            'feature_count' => $count,
            'published' => false,
            'metadata' => [
                'overlay_operation' => $operation,
                'source_layer_a' => $layerA->id,
                'source_layer_b' => $layerB->id,
            ],
        ]);
    }
}
