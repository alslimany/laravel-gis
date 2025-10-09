<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class QueryBuilder
{
    /**
     * Build a spatial query for features within a distance.
     *
     * @param  string  $tableName  Name of the PostGIS table
     * @param  string  $geometryColumn  Name of the geometry column
     * @param  float  $latitude  Latitude of the point
     * @param  float  $longitude  Longitude of the point
     * @param  float  $distance  Distance in meters
     * @return \Illuminate\Support\Collection
     */
    public static function withinDistance(
        string $tableName,
        string $geometryColumn,
        float $latitude,
        float $longitude,
        float $distance
    ): \Illuminate\Support\Collection {
        $sql = "SELECT *, ST_AsGeoJSON({$geometryColumn}) as geojson,
                ST_Distance({$geometryColumn}::geography, ST_MakePoint(?, ?)::geography) as distance
                FROM {$tableName}
                WHERE ST_DWithin({$geometryColumn}::geography, ST_MakePoint(?, ?)::geography, ?)
                ORDER BY distance";

        return collect(DB::select($sql, [$longitude, $latitude, $longitude, $latitude, $distance]));
    }

    /**
     * Build a spatial query for features intersecting a geometry.
     *
     * @param  string  $tableName  Name of the PostGIS table
     * @param  string  $geometryColumn  Name of the geometry column
     * @param  string  $wkt  Geometry in WKT format
     * @return \Illuminate\Support\Collection
     */
    public static function intersects(
        string $tableName,
        string $geometryColumn,
        string $wkt
    ): \Illuminate\Support\Collection {
        $sql = "SELECT *, ST_AsGeoJSON({$geometryColumn}) as geojson
                FROM {$tableName}
                WHERE ST_Intersects({$geometryColumn}, ST_GeomFromText(?, 4326))";

        return collect(DB::select($sql, [$wkt]));
    }

    /**
     * Build a spatial query for features within a geometry.
     *
     * @param  string  $tableName  Name of the PostGIS table
     * @param  string  $geometryColumn  Name of the geometry column
     * @param  string  $wkt  Geometry in WKT format
     * @return \Illuminate\Support\Collection
     */
    public static function within(
        string $tableName,
        string $geometryColumn,
        string $wkt
    ): \Illuminate\Support\Collection {
        $sql = "SELECT *, ST_AsGeoJSON({$geometryColumn}) as geojson
                FROM {$tableName}
                WHERE ST_Within({$geometryColumn}, ST_GeomFromText(?, 4326))";

        return collect(DB::select($sql, [$wkt]));
    }

    /**
     * Build a spatial query for features containing a geometry.
     *
     * @param  string  $tableName  Name of the PostGIS table
     * @param  string  $geometryColumn  Name of the geometry column
     * @param  string  $wkt  Geometry in WKT format
     * @return \Illuminate\Support\Collection
     */
    public static function contains(
        string $tableName,
        string $geometryColumn,
        string $wkt
    ): \Illuminate\Support\Collection {
        $sql = "SELECT *, ST_AsGeoJSON({$geometryColumn}) as geojson
                FROM {$tableName}
                WHERE ST_Contains({$geometryColumn}, ST_GeomFromText(?, 4326))";

        return collect(DB::select($sql, [$wkt]));
    }

    /**
     * Build an attribute query with conditions.
     *
     * @param  string  $tableName  Name of the table
     * @param  array  $conditions  Array of conditions [['column' => 'name', 'operator' => '=', 'value' => 'test']]
     * @param  string  $geometryColumn  Optional geometry column to include GeoJSON
     * @return \Illuminate\Support\Collection
     */
    public static function attributeQuery(
        string $tableName,
        array $conditions,
        ?string $geometryColumn = null
    ): \Illuminate\Support\Collection {
        $query = DB::table($tableName);

        // Add geometry column if provided
        if ($geometryColumn) {
            $query->selectRaw("*, ST_AsGeoJSON({$geometryColumn}) as geojson");
        } else {
            $query->select('*');
        }

        // Apply conditions
        foreach ($conditions as $condition) {
            $column = $condition['column'] ?? null;
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'] ?? null;

            if ($column && $value !== null) {
                $query->where($column, $operator, $value);
            }
        }

        return $query->get();
    }

    /**
     * Calculate buffer analysis on features.
     *
     * @param  string  $tableName  Name of the PostGIS table
     * @param  string  $geometryColumn  Name of the geometry column
     * @param  float  $distance  Buffer distance in meters
     * @param  array  $conditions  Optional attribute conditions
     * @return \Illuminate\Support\Collection
     */
    public static function bufferAnalysis(
        string $tableName,
        string $geometryColumn,
        float $distance,
        array $conditions = []
    ): \Illuminate\Support\Collection {
        $whereClause = '';
        $bindings = [];

        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $condition) {
                $column = $condition['column'] ?? null;
                $operator = $condition['operator'] ?? '=';
                $value = $condition['value'] ?? null;

                if ($column && $value !== null) {
                    $whereParts[] = "{$column} {$operator} ?";
                    $bindings[] = $value;
                }
            }
            if (!empty($whereParts)) {
                $whereClause = 'WHERE '.implode(' AND ', $whereParts);
            }
        }

        $bindings[] = $distance;

        $sql = "SELECT *, 
                ST_AsGeoJSON({$geometryColumn}) as original_geojson,
                ST_AsGeoJSON(ST_Buffer({$geometryColumn}::geography, ?)::geometry) as buffer_geojson
                FROM {$tableName}
                {$whereClause}";

        return collect(DB::select($sql, $bindings));
    }
}
