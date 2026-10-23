<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Models\Basin;

/**
 * Upsert global ew_basins (attributes + geometry) from the pipeline GeoJSON,
 * keyed by basin_code. Optionally link each basin to an imported org layer
 * (a view only; the geometry of record is ew_basins.geom).
 *
 *   php artisan early-warning:link-basins packages/early-warning/data/out/tripoli_basins.geojson [--layer=12]
 *
 * Fails closed: every feature is schema-checked first and nothing is written
 * if any feature is invalid. --layer must have metadata.plugin = early-warning.
 */
class LinkBasinsCommand extends Command
{
    protected $signature = 'early-warning:link-basins
        {geojson : Path to basins GeoJSON produced by tools/delineate_basins.py}
        {--layer= : Optional layers.id of an imported basin layer (metadata.plugin=early-warning)}
        {--region=tripoli : Region tag}';

    protected $description = 'Upsert global ew_basins from basin GeoJSON, optionally linking an imported layer';

    private const NUMERIC = ['area_km2', 'mean_slope_deg', 'max_slope_deg', 'max_flow_acc_cells', 'max_flow_acc_km2', 'outlet_lon', 'outlet_lat'];

    private const STRINGS = ['name', 'name_ar', 'dem_source', 'dem_version', 'processed_at'];

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
        if (($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null) || $data['features'] === []) {
            $this->error('Expected a non-empty GeoJSON FeatureCollection.');

            return self::FAILURE;
        }

        $errors = [];
        foreach ($data['features'] as $i => $feature) {
            foreach ($this->validateFeature($feature) as $e) {
                $errors[] = "Feature #{$i}: {$e}";
            }
        }
        if ($errors) {
            foreach ($errors as $e) {
                $this->error($e);
            }
            $this->error('No basins written.');

            return self::FAILURE;
        }

        $layerId = $this->option('layer') !== null ? (int) $this->option('layer') : null;
        $featureIds = [];
        if ($layerId !== null) {
            $featureIds = $this->featureIdsFromLayer($layerId);
            if ($featureIds === null) {
                return self::FAILURE;
            }
        }

        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($pgsql) {
            $empty = [];
            foreach ($data['features'] as $i => $feature) {
                if ($this->cleanedGeometryIsEmpty($feature['geometry'])) {
                    $empty[] = "Feature #{$i} ({$feature['properties']['basin_code']}): geometry is empty after ST_MakeValid/ST_CollectionExtract";
                }
            }
            if ($empty) {
                foreach ($empty as $e) {
                    $this->error($e);
                }
                $this->error('No basins written.');

                return self::FAILURE;
            }
        }

        $count = 0;
        DB::transaction(function () use ($data, $layerId, $featureIds, $pgsql, &$count) {
            foreach ($data['features'] as $feature) {
                $p = $feature['properties'];
                $code = $p['basin_code'];

                $row = [
                    'name' => $p['name'] ?? $code,
                    'name_ar' => $p['name_ar'] ?? null,
                    'region' => $this->option('region'),
                    'layer_id' => $layerId,
                    'feature_id' => $layerId === null ? null
                        : (isset($featureIds[$code]) ? (string) $featureIds[$code] : (isset($feature['id']) ? (string) $feature['id'] : null)),
                    'dem_source' => $p['dem_source'] ?? null,
                    'dem_version' => $p['dem_version'] ?? null,
                    'processed_at' => $p['processed_at'] ?? null,
                ];
                foreach (self::NUMERIC as $k) {
                    $row[$k] = $p[$k] ?? null;
                }
                $known = array_merge(array_keys($row), ['basin_code']);
                $row['props'] = array_diff_key($p, array_flip($known)) ?: null;

                Basin::updateOrCreate(['basin_code' => $code], $row);

                $geojson = json_encode($feature['geometry']);
                if ($pgsql) {
                    DB::update(
                        'UPDATE ew_basins SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3)) WHERE basin_code = ?',
                        [$geojson, $code]
                    );
                } else {
                    DB::table('ew_basins')->where('basin_code', $code)->update(['geom' => $geojson]);
                }
                $count++;
            }
        });

        $this->info("Linked {$count} basin(s)".($layerId ? " to layer {$layerId}" : '').'.');

        return self::SUCCESS;
    }

    /** Same cleaning expression as the write path; true when nothing polygonal survives. */
    private function cleanedGeometryIsEmpty(array $geometry): bool
    {
        return (bool) DB::scalar(
            'SELECT COALESCE(ST_IsEmpty(ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3))), true)',
            [json_encode($geometry)]
        );
    }

    /** @return list<string> problems with one feature (empty = valid) */
    private function validateFeature(mixed $feature): array
    {
        if (! is_array($feature) || ($feature['type'] ?? null) !== 'Feature') {
            return ['not a GeoJSON Feature'];
        }
        $e = [];
        $p = $feature['properties'] ?? null;
        if (! is_array($p)) {
            return ['missing properties'];
        }
        if (! is_string($p['basin_code'] ?? null) || trim($p['basin_code']) === '') {
            $e[] = 'missing basin_code';
        }
        foreach (self::NUMERIC as $k) {
            if (isset($p[$k]) && ! is_int($p[$k]) && ! is_float($p[$k])) {
                $e[] = "{$k} must be numeric";
            }
        }
        foreach (self::STRINGS as $k) {
            if (isset($p[$k]) && ! is_string($p[$k])) {
                $e[] = "{$k} must be a string";
            }
        }
        $g = $feature['geometry'] ?? null;
        if (! is_array($g) || ! in_array($g['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
            || ! is_array($g['coordinates'] ?? null) || $g['coordinates'] === []) {
            $e[] = 'geometry must be a non-empty Polygon or MultiPolygon';
        }

        return $e;
    }

    /**
     * @return array<string,mixed>|null basin_code => feature id, or null (error already printed)
     */
    private function featureIdsFromLayer(int $layerId): ?array
    {
        $layer = DB::table('layers')->where('id', $layerId)->first();
        if (! $layer) {
            $this->error("Layer {$layerId} not found.");

            return null;
        }
        $meta = is_string($layer->metadata ?? null) ? json_decode($layer->metadata, true) : (array) ($layer->metadata ?? []);
        if (($meta['plugin'] ?? null) !== 'early-warning') {
            $this->error("Layer {$layerId} is not an early-warning layer (metadata.plugin must be \"early-warning\").");

            return null;
        }
        $table = $layer->table_name;
        if (! $table || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'basin_code')) {
            return [];
        }
        $pk = collect(['gid', 'id', 'fid', 'ogc_fid'])->first(fn ($c) => Schema::hasColumn($table, $c));

        return $pk ? DB::table($table)->pluck($pk, 'basin_code')->all() : [];
    }
}
