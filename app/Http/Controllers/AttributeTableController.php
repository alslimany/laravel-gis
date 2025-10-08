<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttributeTableController extends Controller
{
    /**
     * Display attribute table for a layer.
     */
    public function index(Layer $layer, Request $request)
    {
        $this->authorize('view', $layer);

        $perPage = $request->input('per_page', 25);
        $search = $request->input('search');

        // Get table columns
        $columns = $this->getTableColumns($layer->table_name);

        // Build query
        $query = DB::table($layer->table_name);

        // Apply search filter if provided
        if ($search) {
            $query->where(function ($q) use ($search, $columns) {
                foreach ($columns as $column) {
                    if (!in_array($column, ['geom', 'geometry'])) {
                        $q->orWhere($column, 'ILIKE', "%{$search}%");
                    }
                }
            });
        }

        // Get paginated results
        $features = $query->paginate($perPage);

        // Convert geometry columns to WKT for display
        $features->getCollection()->transform(function ($feature) use ($columns) {
            $feature = (array) $feature;
            foreach ($columns as $column) {
                if (in_array($column, ['geom', 'geometry']) && isset($feature[$column])) {
                    try {
                        $wkt = DB::select("SELECT ST_AsText(?) as wkt", [$feature[$column]])[0]->wkt ?? null;
                        $feature[$column] = $wkt;
                    } catch (\Exception $e) {
                        $feature[$column] = 'Error reading geometry';
                    }
                }
            }
            return (object) $feature;
        });

        return view('layers.attributes.index', compact('layer', 'features', 'columns'));
    }

    /**
     * Update a feature attribute.
     */
    public function update(Layer $layer, Request $request, $featureId)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'column' => 'required|string',
            'value' => 'nullable|string',
        ]);

        $columns = $this->getTableColumns($layer->table_name);

        // Ensure column exists and is not a geometry column
        if (!in_array($validated['column'], $columns) || in_array($validated['column'], ['geom', 'geometry'])) {
            return response()->json(['error' => 'Invalid column'], 400);
        }

        try {
            DB::table($layer->table_name)
                ->where('id', $featureId)
                ->update([
                    $validated['column'] => $validated['value']
                ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get GeoJSON data for a layer.
     */
    public function geojson(Layer $layer)
    {
        $this->authorize('view', $layer);

        try {
            // Get all features as GeoJSON
            $features = DB::select("
                SELECT jsonb_build_object(
                    'type', 'FeatureCollection',
                    'features', jsonb_agg(feature)
                ) as geojson
                FROM (
                    SELECT jsonb_build_object(
                        'type', 'Feature',
                        'geometry', ST_AsGeoJSON(geom)::jsonb,
                        'properties', to_jsonb(row) - 'geom'
                    ) as feature
                    FROM (SELECT * FROM {$layer->table_name}) row
                ) features
            ");

            $geojson = json_decode($features[0]->geojson ?? '{}', true);

            return response()->json($geojson);
        } catch (\Exception $e) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get table columns excluding geometry.
     */
    protected function getTableColumns(string $tableName): array
    {
        try {
            return Schema::getColumnListing($tableName);
        } catch (\Exception $e) {
            return [];
        }
    }
}
