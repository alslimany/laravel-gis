<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class SpatialHelper
{
    /**
     * Convert WKT to GeoJSON.
     */
    public static function wktToGeoJson(string $wkt): ?array
    {
        $result = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_GeomFromText(?, 4326)) as geojson',
            [$wkt]
        );

        return $result ? json_decode($result->geojson, true) : null;
    }

    /**
     * Convert GeoJSON to WKT.
     *
     * @param  array|string  $geojson
     */
    public static function geoJsonToWkt($geojson): ?string
    {
        if (is_array($geojson)) {
            $geojson = json_encode($geojson);
        }

        $result = DB::selectOne(
            'SELECT ST_AsText(ST_GeomFromGeoJSON(?)) as wkt',
            [$geojson]
        );

        return $result->wkt ?? null;
    }

    /**
     * Create a point from latitude and longitude.
     *
     * @return string WKT point
     */
    public static function makePoint(float $latitude, float $longitude): string
    {
        return "POINT({$longitude} {$latitude})";
    }

    /**
     * Create a polygon from coordinates array.
     *
     * @param  array  $coordinates  Array of [longitude, latitude] pairs
     * @return string WKT polygon
     */
    public static function makePolygon(array $coordinates): string
    {
        $points = array_map(function ($coord) {
            return "{$coord[0]} {$coord[1]}";
        }, $coordinates);

        // Ensure the polygon is closed
        if ($coordinates[0] !== end($coordinates)) {
            $points[] = "{$coordinates[0][0]} {$coordinates[0][1]}";
        }

        return 'POLYGON(('.implode(', ', $points).'))';
    }

    /**
     * Calculate distance between two points in meters.
     *
     * @return float Distance in meters
     */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $result = DB::selectOne(
            'SELECT ST_Distance(
                ST_MakePoint(?, ?)::geography,
                ST_MakePoint(?, ?)::geography
            ) as distance',
            [$lon1, $lat1, $lon2, $lat2]
        );

        return (float) $result->distance;
    }

    /**
     * Check if a point is within a polygon.
     */
    public static function pointInPolygon(float $latitude, float $longitude, string $polygonWkt): bool
    {
        $result = DB::selectOne(
            'SELECT ST_Within(
                ST_MakePoint(?, ?)::geography,
                ST_GeomFromText(?, 4326)
            ) as within',
            [$longitude, $latitude, $polygonWkt]
        );

        return (bool) $result->within;
    }

    /**
     * Get the center point of a geometry.
     *
     * @return array [latitude, longitude]
     */
    public static function centroid(string $wkt): array
    {
        $result = DB::selectOne(
            'SELECT ST_Y(ST_Centroid(ST_GeomFromText(?, 4326))) as lat,
                    ST_X(ST_Centroid(ST_GeomFromText(?, 4326))) as lon',
            [$wkt, $wkt]
        );

        return [(float) $result->lat, (float) $result->lon];
    }

    /**
     * Get bounding box of a geometry.
     *
     * @return array [min_lon, min_lat, max_lon, max_lat]
     */
    public static function boundingBox(string $wkt): array
    {
        $result = DB::selectOne(
            'SELECT ST_XMin(ST_GeomFromText(?, 4326)) as min_lon,
                    ST_YMin(ST_GeomFromText(?, 4326)) as min_lat,
                    ST_XMax(ST_GeomFromText(?, 4326)) as max_lon,
                    ST_YMax(ST_GeomFromText(?, 4326)) as max_lat',
            [$wkt, $wkt, $wkt, $wkt]
        );

        return [
            (float) $result->min_lon,
            (float) $result->min_lat,
            (float) $result->max_lon,
            (float) $result->max_lat,
        ];
    }

    /**
     * Create a buffer around a geometry.
     *
     * @param  string  $wkt  Geometry in WKT format
     * @param  float  $distance  Buffer distance in meters
     * @return string Buffered geometry in WKT format
     */
    public static function buffer(string $wkt, float $distance): string
    {
        $result = DB::selectOne(
            'SELECT ST_AsText(ST_Buffer(ST_GeomFromText(?, 4326)::geography, ?)::geometry) as wkt',
            [$wkt, $distance]
        );

        return $result->wkt;
    }

    /**
     * Check if two geometries intersect.
     *
     * @param  string  $wkt1  First geometry in WKT format
     * @param  string  $wkt2  Second geometry in WKT format
     * @return bool
     */
    public static function intersects(string $wkt1, string $wkt2): bool
    {
        $result = DB::selectOne(
            'SELECT ST_Intersects(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as intersects',
            [$wkt1, $wkt2]
        );

        return (bool) $result->intersects;
    }

    /**
     * Check if first geometry contains the second.
     *
     * @param  string  $wkt1  Container geometry in WKT format
     * @param  string  $wkt2  Contained geometry in WKT format
     * @return bool
     */
    public static function contains(string $wkt1, string $wkt2): bool
    {
        $result = DB::selectOne(
            'SELECT ST_Contains(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as contains',
            [$wkt1, $wkt2]
        );

        return (bool) $result->contains;
    }

    /**
     * Check if first geometry is within the second.
     *
     * @param  string  $wkt1  Inner geometry in WKT format
     * @param  string  $wkt2  Outer geometry in WKT format
     * @return bool
     */
    public static function within(string $wkt1, string $wkt2): bool
    {
        $result = DB::selectOne(
            'SELECT ST_Within(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as within',
            [$wkt1, $wkt2]
        );

        return (bool) $result->within;
    }

    /**
     * Calculate the area of a geometry in square meters.
     *
     * @param  string  $wkt  Geometry in WKT format
     * @return float Area in square meters
     */
    public static function area(string $wkt): float
    {
        $result = DB::selectOne(
            'SELECT ST_Area(ST_GeomFromText(?, 4326)::geography) as area',
            [$wkt]
        );

        return (float) $result->area;
    }

    /**
     * Calculate the length/perimeter of a geometry in meters.
     *
     * @param  string  $wkt  Geometry in WKT format
     * @return float Length in meters
     */
    public static function length(string $wkt): float
    {
        $result = DB::selectOne(
            'SELECT ST_Length(ST_GeomFromText(?, 4326)::geography) as length',
            [$wkt]
        );

        return (float) $result->length;
    }

    /**
     * Create a LineString from coordinates array.
     *
     * @param  array  $coordinates  Array of [longitude, latitude] pairs
     * @return string WKT linestring
     */
    public static function makeLineString(array $coordinates): string
    {
        $points = array_map(function ($coord) {
            return "{$coord[0]} {$coord[1]}";
        }, $coordinates);

        return 'LINESTRING('.implode(', ', $points).')';
    }
}
