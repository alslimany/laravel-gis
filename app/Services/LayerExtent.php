<?php

namespace App\Services;

use App\Helpers\GeometryColumnHelper;
use App\Helpers\SpatialHelper;
use App\Models\Layer;
use App\Models\Map;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WGS84 extent of a layer, used to open a map on the data instead of the world.
 *
 * Resolution order: a stored bbox (raster imports keep one), then the geometry
 * column itself. Every answer is cached against the layer's row count so a
 * dashboard render never scans a table twice.
 */
class LayerExtent
{
    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null [minLon, minLat, maxLon, maxLat]
     */
    public function for(?Layer $layer): ?array
    {
        if (! $layer || ! $layer->table_name) {
            return null;
        }

        $signature = implode(':', [
            $layer->id,
            $layer->feature_count ?? 0,
            $layer->updated_at?->getTimestamp() ?? 0,
        ]);

        $extent = Cache::remember("layer_extent:{$signature}", 600, function () use ($layer) {
            return $this->compute($layer);
        });

        return self::coerceBounds($extent);
    }

    /**
     * Union of the extents of every layer a saved map shows.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function forMap(?Map $map): ?array
    {
        if (! $map) {
            return null;
        }

        $ids = array_values(array_filter(array_map(
            fn ($layer) => is_array($layer) ? ($layer['id'] ?? null) : null,
            is_array($map->layers) ? $map->layers : []
        ), 'is_numeric'));

        if ($ids === []) {
            return null;
        }

        $union = null;
        $layers = Layer::query()
            ->where('organization_id', $map->organization_id)
            ->whereIn('id', $ids)
            ->limit(12)
            ->get();

        foreach ($layers as $layer) {
            $extent = $this->for($layer);
            if ($extent === null) {
                continue;
            }
            $union = $union === null
                ? $extent
                : [
                    min($union[0], $extent[0]),
                    min($union[1], $extent[1]),
                    max($union[2], $extent[2]),
                    max($union[3], $extent[3]),
                ];
        }

        return $union;
    }

    /**
     * A viewport that frames the given bounds.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds
     * @return array{center: array{0: float, 1: float}, zoom: int, rotation: int, bounds: array{0: float, 1: float, 2: float, 3: float}}
     */
    public static function toViewport(array $bounds): array
    {
        $bounds = self::coerceBounds($bounds) ?? [0.0, 0.0, 0.0, 0.0];
        $span = max($bounds[2] - $bounds[0], $bounds[3] - $bounds[1], 1e-6);
        $zoom = (int) round(log(360 / ($span * 1.5), 2));

        return [
            'center' => [
                round(($bounds[0] + $bounds[2]) / 2, 6),
                round(($bounds[1] + $bounds[3]) / 2, 6),
            ],
            'zoom' => max(2, min(18, $zoom)),
            'rotation' => 0,
            'bounds' => $bounds,
        ];
    }

    /**
     * Accept an array of four numbers or a "minLon, minLat, maxLon, maxLat" string.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function coerceBounds(mixed $value): ?array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', trim($value)) ?: [];
            $value = array_values(array_filter($parts, fn ($part) => $part !== ''));
        }

        if (! is_array($value) || count($value) !== 4) {
            return null;
        }

        foreach ($value as $coordinate) {
            if (! is_numeric($coordinate) || ! is_finite((float) $coordinate)) {
                return null;
            }
        }

        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', array_values($value));

        if ($minLon > $maxLon || $minLat > $maxLat) {
            return null;
        }

        if ($minLon < -180.0001 || $maxLon > 180.0001 || $minLat < -90.0001 || $maxLat > 90.0001) {
            return null;
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected function compute(Layer $layer): ?array
    {
        $table = $layer->table_name;

        try {
            if (! Schema::hasTable($table) && ! Schema::hasView($table)) {
                return null;
            }

            $stored = self::coerceBounds(data_get($layer->metadata, 'bbox'));
            if ($stored !== null) {
                return $stored;
            }

            $geom = GeometryColumnHelper::resolve($table);
            if (! Schema::hasColumn($table, $geom)) {
                return null;
            }

            return $this->driver() === 'pgsql'
                ? $this->postgis($table, $geom)
                : $this->sampled($table, $geom);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected function postgis(string $table, string $geom): ?array
    {
        $quotedTable = '"'.str_replace('"', '', $table).'"';
        $quotedGeom = '"'.str_replace('"', '', $geom).'"';

        $row = DB::selectOne(
            "SELECT ST_XMin(e) AS min_lon, ST_YMin(e) AS min_lat, ST_XMax(e) AS max_lon, ST_YMax(e) AS max_lat
             FROM (SELECT ST_Extent({$quotedGeom}) AS e FROM {$quotedTable} WHERE {$quotedGeom} IS NOT NULL) AS extent"
        );

        if (! $row || $row->min_lon === null || $row->max_lon === null) {
            return null;
        }

        return self::coerceBounds([$row->min_lon, $row->min_lat, $row->max_lon, $row->max_lat]);
    }

    /**
     * Databases without ST_Extent (the sqlite test harness) get a bounded sample.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected function sampled(string $table, string $geom): ?array
    {
        $rows = DB::table($table)->whereNotNull($geom)->limit(1000)->pluck($geom);

        $bounds = null;
        foreach ($rows as $wkt) {
            if (! is_string($wkt) || $wkt === '') {
                continue;
            }

            try {
                $box = SpatialHelper::boundingBox($wkt);
            } catch (\Throwable) {
                continue;
            }

            $bounds = $bounds === null
                ? $box
                : [
                    min($bounds[0], $box[0]),
                    min($bounds[1], $box[1]),
                    max($bounds[2], $box[2]),
                    max($bounds[3], $box[3]),
                ];
        }

        return self::coerceBounds($bounds);
    }

    protected function driver(): string
    {
        try {
            return (string) DB::connection()->getDriverName();
        } catch (\Throwable) {
            return '';
        }
    }
}
