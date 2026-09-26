<?php

namespace App\Http\Controllers;

use App\Helpers\GeometryColumnHelper;
use App\Models\Layer;
use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);

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
            return $this->exportFailed($request, $layer, $e);
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
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);

            // Get all features with geometry as WKT
            $features = DB::select(
                "SELECT *, ST_AsText({$geometryColumn}) as wkt 
                 FROM {$layer->table_name}"
            );

            if (empty($features)) {
                return $this->exportFailed($request, $layer, new \RuntimeException('This layer has no features to export.'), 404);
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
            return $this->exportFailed($request, $layer, $e);
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
     * Export layer attributes to Excel (xlsx).
     */
    public function exportExcel(Request $request, Layer $layer)
    {
        if ($layer->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized');
        }

        try {
            $geometryColumn = GeometryColumnHelper::resolve($layer->table_name);

            $features = DB::select(
                "SELECT *, ST_AsText({$geometryColumn}) as wkt FROM {$layer->table_name}"
            );

            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($layer->name, 0, 31) ?: 'Layer');

            if (empty($features)) {
                $sheet->setCellValue('A1', 'No data');
            } else {
                $first = (array) $features[0];
                unset($first[$geometryColumn]);
                $headers = array_keys($first);

                foreach ($headers as $index => $header) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
                }

                $rowNum = 2;
                foreach ($features as $feature) {
                    $row = (array) $feature;
                    unset($row[$geometryColumn]);
                    $col = 1;
                    foreach ($headers as $header) {
                        $value = $row[$header] ?? null;
                        if (is_bool($value)) {
                            $value = $value ? '1' : '0';
                        }
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$rowNum, $value);
                        $col++;
                    }
                    $rowNum++;
                }
            }

            $filename = str_replace(' ', '_', $layer->name).'_'.date('Y-m-d').'.xlsx';
            $tempPath = storage_path('app/temp_'.$layer->id.'_'.uniqid().'.xlsx');

            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);

            return response()->download($tempPath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return $this->exportFailed($request, $layer, $e);
        }
    }

    /**
     * Browser downloads go back to the layer with the reason. API clients still get JSON.
     */
    protected function exportFailed(Request $request, Layer $layer, \Throwable $e, int $status = 500)
    {
        if ($request->expectsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json(['error' => $e->getMessage()], $status);
        }

        return redirect()
            ->route('layers.show', $layer)
            ->with('error', 'Export failed: '.$e->getMessage());
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
