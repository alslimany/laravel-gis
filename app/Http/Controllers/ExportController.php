<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Export layer data as GeoJSON.
     */
    public function exportGeoJSON(Request $request, Layer $layer)
    {
        // Check authorization
        if ($layer->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized');
        }

        try {
            $geometryColumn = 'geometry'; // Default column name
            
            // Get all features with geometry as GeoJSON
            $features = DB::select(
                "SELECT *, ST_AsGeoJSON({$geometryColumn}) as geojson 
                 FROM {$layer->table_name}"
            );

            // Build GeoJSON FeatureCollection
            $geojson = [
                'type' => 'FeatureCollection',
                'features' => array_map(function ($feature) use ($geometryColumn) {
                    $properties = (array) $feature;
                    $geometry = json_decode($properties['geojson'], true);
                    unset($properties['geojson']);
                    unset($properties[$geometryColumn]);

                    return [
                        'type' => 'Feature',
                        'geometry' => $geometry,
                        'properties' => $properties,
                    ];
                }, $features),
            ];

            $filename = str_replace(' ', '_', $layer->name).'_'.date('Y-m-d').'.geojson';

            return response()->json($geojson)
                ->header('Content-Type', 'application/geo+json')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export layer data as CSV.
     */
    public function exportCSV(Request $request, Layer $layer)
    {
        // Check authorization
        if ($layer->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized');
        }

        try {
            $geometryColumn = 'geometry'; // Default column name
            
            // Get all features with geometry as WKT
            $features = DB::select(
                "SELECT *, ST_AsText({$geometryColumn}) as wkt 
                 FROM {$layer->table_name}"
            );

            if (empty($features)) {
                return response()->json(['error' => 'No data to export'], 404);
            }

            // Create CSV content
            $output = fopen('php://temp', 'r+');
            
            // Write header
            $firstFeature = (array) $features[0];
            unset($firstFeature[$geometryColumn]);
            fputcsv($output, array_keys($firstFeature));

            // Write data
            foreach ($features as $feature) {
                $row = (array) $feature;
                unset($row[$geometryColumn]);
                fputcsv($output, $row);
            }

            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            $filename = str_replace(' ', '_', $layer->name).'_'.date('Y-m-d').'.csv';

            return response($csv)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export map configuration as JSON.
     */
    public function exportMapConfig(Request $request, Map $map)
    {
        // Check authorization
        if ($map->user->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized');
        }

        $config = [
            'name' => $map->name,
            'description' => $map->description,
            'basemap' => $map->basemap,
            'viewport' => $map->viewport,
            'layers' => $map->layers,
            'exported_at' => now()->toISOString(),
        ];

        $filename = str_replace(' ', '_', $map->name).'_config_'.date('Y-m-d').'.json';

        return response()->json($config, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Prepare map data for PNG export (client-side).
     */
    public function prepareMapExport(Request $request, Map $map)
    {
        // Check authorization
        if ($map->user->organization_id !== auth()->user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Return map configuration for client-side rendering and export
        return response()->json([
            'success' => true,
            'map' => [
                'name' => $map->name,
                'description' => $map->description,
                'basemap' => $map->basemap,
                'viewport' => $map->viewport,
                'layers' => $map->layers,
            ],
        ]);
    }

    /**
     * Export query results as GeoJSON.
     */
    public function exportQueryResults(Request $request)
    {
        $request->validate([
            'layer_id' => 'required|exists:layers,id',
            'features' => 'required|array',
        ]);

        $layer = Layer::findOrFail($request->layer_id);

        // Check authorization
        if ($layer->organization_id !== auth()->user()->organization_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            // Build GeoJSON from provided features
            $geojson = [
                'type' => 'FeatureCollection',
                'features' => $request->features,
            ];

            $filename = str_replace(' ', '_', $layer->name).'_query_'.date('Y-m-d').'.geojson';

            return response()->json($geojson)
                ->header('Content-Type', 'application/geo+json')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
