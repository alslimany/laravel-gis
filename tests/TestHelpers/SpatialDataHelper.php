<?php

namespace Tests\TestHelpers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Helper class for generating test spatial data
 */
class SpatialDataHelper
{
    /**
     * Create a point geometry in WKT format
     */
    public static function createPoint(float $longitude, float $latitude): string
    {
        return "POINT({$longitude} {$latitude})";
    }

    /**
     * Create a polygon geometry in WKT format
     */
    public static function createPolygon(array $coordinates): string
    {
        $points = array_map(fn($coord) => "{$coord[0]} {$coord[1]}", $coordinates);
        $points[] = $points[0]; // Close the polygon
        return 'POLYGON((' . implode(', ', $points) . '))';
    }

    /**
     * Create a linestring geometry in WKT format
     */
    public static function createLineString(array $coordinates): string
    {
        $points = array_map(fn($coord) => "{$coord[0]} {$coord[1]}", $coordinates);
        return 'LINESTRING(' . implode(', ', $points) . ')';
    }

    /**
     * Generate random coordinates within bounds
     */
    public static function randomCoordinates(
        float $minLon = -180,
        float $maxLon = 180,
        float $minLat = -90,
        float $maxLat = 90
    ): array {
        return [
            'longitude' => $minLon + mt_rand() / mt_getrandmax() * ($maxLon - $minLon),
            'latitude' => $minLat + mt_rand() / mt_getrandmax() * ($maxLat - $minLat),
        ];
    }

    /**
     * Create a bounding box polygon around a center point
     */
    public static function createBoundingBox(
        float $centerLon,
        float $centerLat,
        float $size = 0.1
    ): string {
        $halfSize = $size / 2;
        $coordinates = [
            [$centerLon - $halfSize, $centerLat - $halfSize],
            [$centerLon + $halfSize, $centerLat - $halfSize],
            [$centerLon + $halfSize, $centerLat + $halfSize],
            [$centerLon - $halfSize, $centerLat + $halfSize],
        ];
        return self::createPolygon($coordinates);
    }

    /**
     * Create sample GeoJSON feature
     */
    public static function createGeoJsonFeature(
        string $geometry,
        array $properties = []
    ): array {
        return [
            'type' => 'Feature',
            'geometry' => self::wktToGeoJson($geometry),
            'properties' => $properties,
        ];
    }

    /**
     * Convert WKT to GeoJSON geometry
     */
    public static function wktToGeoJson(string $wkt): array
    {
        if (preg_match('/POINT\(([-\d.]+)\s+([-\d.]+)\)/', $wkt, $matches)) {
            return [
                'type' => 'Point',
                'coordinates' => [(float)$matches[1], (float)$matches[2]],
            ];
        }

        if (preg_match('/POLYGON\(\((.*?)\)\)/', $wkt, $matches)) {
            $coords = array_map(function ($point) {
                [$lon, $lat] = explode(' ', trim($point));
                return [(float)$lon, (float)$lat];
            }, explode(',', $matches[1]));
            return [
                'type' => 'Polygon',
                'coordinates' => [$coords],
            ];
        }

        if (preg_match('/LINESTRING\((.*?)\)/', $wkt, $matches)) {
            $coords = array_map(function ($point) {
                [$lon, $lat] = explode(' ', trim($point));
                return [(float)$lon, (float)$lat];
            }, explode(',', $matches[1]));
            return [
                'type' => 'LineString',
                'coordinates' => $coords,
            ];
        }

        throw new \InvalidArgumentException("Unsupported WKT format: {$wkt}");
    }

    /**
     * Create sample shapefile data for testing
     */
    public static function getSampleShapefileData(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [-122.4194, 37.7749],
                    ],
                    'properties' => [
                        'name' => 'San Francisco',
                        'population' => 884363,
                    ],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [-118.2437, 34.0522],
                    ],
                    'properties' => [
                        'name' => 'Los Angeles',
                        'population' => 3979576,
                    ],
                ],
            ],
        ];
    }

    /**
     * Create a circle polygon approximation
     */
    public static function createCircle(
        float $centerLon,
        float $centerLat,
        float $radiusMeters,
        int $segments = 32
    ): string {
        $coords = [];
        $radiusDegrees = $radiusMeters / 111320; // Approximate meters to degrees

        for ($i = 0; $i <= $segments; $i++) {
            $angle = ($i * 2 * M_PI) / $segments;
            $coords[] = [
                $centerLon + $radiusDegrees * cos($angle),
                $centerLat + $radiusDegrees * sin($angle),
            ];
        }

        return self::createPolygon($coords);
    }
}
