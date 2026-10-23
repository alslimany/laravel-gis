<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Models\Basin;

/**
 * Upsert ew_basins from the pipeline GeoJSON, keyed by basin_code, and link
 * each basin to the layer created by the normal Data Import flow.
 *
 *   php artisan early-warning:link-basins packages/early-warning/data/out/tripoli_basins.geojson --layer=12
 *
 * feature_id is resolved from the layer's import table (column basin_code)
 * when that table exists; otherwise it falls back to the GeoJSON feature id.
 */
class LinkBasinsCommand extends Command
{
    protected $signature = 'early-warning:link-basins
        {geojson : Path to basins GeoJSON produced by tools/delineate_basins.py}
        {--layer= : layers.id of the imported basin layer}
        {--region=tripoli : Region tag}';

    protected $description = 'Upsert global ew_basins from basin GeoJSON and link them to an imported layer';

    private const NUMERIC = ['area_km2', 'mean_slope_deg', 'max_slope_deg', 'max_flow_acc_cells', 'max_flow_acc_km2', 'outlet_lon', 'outlet_lat'];

    public function handle(): int
    {
        $path = $this->argument('geojson');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $this->error("GeoJSON not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (($data['type'] ?? null) !== 'FeatureCollection') {
            $this->error('Expected a GeoJSON FeatureCollection.');

            return self::FAILURE;
        }

        $layerId = $this->option('layer') !== null ? (int) $this->option('layer') : null;
        $featureIds = $layerId ? $this->featureIdsFromLayer($layerId) : [];
        if ($layerId === -1) {
            return self::FAILURE;
        }

        $count = 0;
        DB::transaction(function () use ($data, $layerId, $featureIds, &$count) {
            foreach ($data['features'] as $i => $feature) {
                $p = $feature['properties'] ?? [];
                $code = $p['basin_code'] ?? null;
                if (! $code) {
                    $this->warn("Feature #{$i} has no basin_code; skipped.");
                    continue;
                }

                $row = [
                    'name' => $p['name'] ?? $code,
                    'name_ar' => $p['name_ar'] ?? null,
                    'region' => $this->option('region'),
                    'layer_id' => $layerId,
                    'feature_id' => isset($featureIds[$code]) ? (string) $featureIds[$code] : (isset($feature['id']) ? (string) $feature['id'] : null),
                    'dem_source' => $p['dem_source'] ?? null,
                    'dem_version' => $p['dem_version'] ?? null,
                    'processed_at' => $p['processed_at'] ?? null,
                ];
                foreach (self::NUMERIC as $k) {
                    $row[$k] = isset($p[$k]) ? $p[$k] : null;
                }
                $known = array_merge(array_keys($row), ['basin_code']);
                $row['attributes'] = array_diff_key($p, array_flip($known)) ?: null;

                Basin::updateOrCreate(['basin_code' => $code], $row);
                $count++;
            }
        });

        $this->info("Linked {$count} basin(s)".($layerId ? " to layer {$layerId}" : '').'.');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> basin_code => feature id */
    private function featureIdsFromLayer(int &$layerId): array
    {
        $layer = DB::table('layers')->where('id', $layerId)->first();
        if (! $layer) {
            $this->error("Layer {$layerId} not found.");
            $layerId = -1;

            return [];
        }
        $table = $layer->table_name;
        if (! $table || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'basin_code')) {
            return [];
        }
        $pk = collect(['gid', 'id', 'fid', 'ogc_fid'])->first(fn ($c) => Schema::hasColumn($table, $c));

        return $pk ? DB::table($table)->pluck($pk, 'basin_code')->all() : [];
    }
}
