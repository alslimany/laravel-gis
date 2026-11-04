<?php

namespace Lumina\Disaster\Support;

use App\Helpers\SpatialHelper;
use Illuminate\Support\Facades\DB;

final class GeometryReader
{
    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public static function pointCoordinates(mixed $geom): ?array
    {
        if ($geom === null) {
            return null;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            // ST_X/ST_Y only accept POINT. Assets/staging areas may be polygons (seeded rectangles),
            // so take a point guaranteed to lie on the shape; for a POINT this is the point itself.
            $row = DB::selectOne(
                'SELECT ST_Y(p) as lat, ST_X(p) as lon FROM (SELECT ST_PointOnSurface(?::geometry) as p) s',
                [$geom],
            );

            return $row
                ? ['latitude' => (float) $row->lat, 'longitude' => (float) $row->lon]
                : null;
        }

        if (! is_string($geom)) {
            return null;
        }

        if (str_starts_with(trim($geom), '{')) {
            $json = json_decode($geom, true);
            if (is_array($json) && ($json['type'] ?? '') === 'Point' && isset($json['coordinates'][0], $json['coordinates'][1])) {
                return [
                    'longitude' => (float) $json['coordinates'][0],
                    'latitude' => (float) $json['coordinates'][1],
                ];
            }
            // Non-point GeoJSON (SQLite path): average of the outer ring / first vertices.
            $ring = match ($json['type'] ?? '') {
                'Polygon' => $json['coordinates'][0] ?? null,
                'MultiPolygon' => $json['coordinates'][0][0] ?? null,
                'LineString', 'MultiPoint' => $json['coordinates'] ?? null,
                default => null,
            };
            if (is_array($ring) && $ring !== []) {
                $xs = array_map(fn ($c) => (float) $c[0], $ring);
                $ys = array_map(fn ($c) => (float) $c[1], $ring);

                return ['longitude' => array_sum($xs) / count($xs), 'latitude' => array_sum($ys) / count($ys)];
            }
        }

        if (preg_match('/POINT\s*\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i', $geom, $matches)) {
            return [
                'longitude' => (float) $matches[1],
                'latitude' => (float) $matches[2],
            ];
        }

        return null;
    }

    /**
     * @return array{type: string, coordinates: mixed}|null
     */
    public static function asGeoJson(mixed $geom): ?array
    {
        if ($geom === null) {
            return null;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne('SELECT ST_AsGeoJSON(?::geometry) as geojson', [$geom]);
            if ($row === null || $row->geojson === null) {
                return null;
            }
            $decoded = json_decode((string) $row->geojson, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (! is_string($geom)) {
            return null;
        }

        $trimmed = trim($geom);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        return SpatialHelper::wktToGeoJson($trimmed);
    }
}
