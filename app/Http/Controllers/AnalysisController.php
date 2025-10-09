<?php

namespace App\Http\Controllers;

use App\Helpers\QueryBuilder;
use App\Helpers\SpatialHelper;
use App\Models\Layer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
            $geometryColumn = 'geometry'; // Default column name
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
            $geometryColumn = 'geometry'; // Default column name
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
            $geometryColumn = 'geometry'; // Default column name
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
}
