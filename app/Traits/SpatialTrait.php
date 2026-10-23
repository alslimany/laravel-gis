<?php

namespace App\Traits;

use App\Helpers\SpatialHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait SpatialTrait
{
    /**
     * Scope to find records within a given distance from a point.
     *
     * @param  float  $distance  Distance in meters
     */
    public function scopeWithinDistance(Builder $query, string $column, float $latitude, float $longitude, float $distance): Builder
    {
        if (! self::usesPostgis()) {
            // SQLite receives float bindings as TEXT, and numeric <= TEXT is always
            // true there, so the threshold has to be an inlined numeric literal.
            return $query->whereRaw(
                self::haversineSql($column).' <= '.sprintf('%.6F', $distance),
                [$latitude, $latitude, $longitude]
            );
        }

        return $query->whereRaw(
            "ST_DWithin({$column}, ST_MakePoint(?, ?)::geography, ?)",
            [$longitude, $latitude, $distance]
        );
    }

    /**
     * Scope to find records near a point, ordered by distance.
     */
    public function scopeNear(Builder $query, string $column, float $latitude, float $longitude): Builder
    {
        if (! self::usesPostgis()) {
            return $query
                ->whereNotNull($column)
                ->selectRaw('*, '.self::haversineSql($column).' as distance', [
                    $latitude, $latitude, $longitude,
                ])
                ->orderBy('distance');
        }

        return $query->selectRaw(
            "*, ST_Distance({$column}, ST_MakePoint(?, ?)::geography) as distance",
            [$longitude, $latitude]
        )->orderBy('distance');
    }

    /**
     * Scope to find records within a polygon.
     *
     * @param  string  $polygon  WKT polygon string
     */
    public function scopeWithinPolygon(Builder $query, string $column, string $polygon): Builder
    {
        if (! self::usesPostgis()) {
            return $query->whereIn(
                $query->getModel()->getKeyName(),
                self::idsMatchingGeometry($query, $column, $polygon, 'within')
            );
        }

        return $query->whereRaw(
            "ST_Within({$column}, ST_GeomFromText(?, 4326))",
            [$polygon]
        );
    }

    /**
     * Scope to find records that intersect with a geometry.
     */
    public function scopeIntersects(Builder $query, string $column, string $geometry): Builder
    {
        if (! self::usesPostgis()) {
            return $query->whereIn(
                $query->getModel()->getKeyName(),
                self::idsMatchingGeometry($query, $column, $geometry, 'intersects')
            );
        }

        return $query->whereRaw(
            "ST_Intersects({$column}, ST_GeomFromText(?, 4326))",
            [$geometry]
        );
    }

    /**
     * Convert spatial attribute to WKT format.
     */
    public function toWKT(string $attribute): ?string
    {
        $value = $this->attributes[$attribute] ?? null;

        if (! $value) {
            return null;
        }

        if (! self::usesPostgis()) {
            return is_string($value) ? $value : null;
        }

        return DB::selectOne(
            'SELECT ST_AsText(?) as wkt',
            [$value]
        )->wkt ?? null;
    }

    /**
     * Convert spatial attribute to GeoJSON format.
     */
    public function toGeoJSON(string $attribute): ?array
    {
        $value = $this->attributes[$attribute] ?? null;

        if (! $value) {
            return null;
        }

        if (! self::usesPostgis()) {
            return is_string($value) ? SpatialHelper::wktToGeoJson($value) : null;
        }

        $geojson = DB::selectOne(
            'SELECT ST_AsGeoJSON(?) as geojson',
            [$value]
        )->geojson ?? null;

        return $geojson ? json_decode($geojson, true) : null;
    }

    /**
     * Set spatial attribute from WKT format.
     */
    public function setFromWKT(string $attribute, string $wkt): void
    {
        $this->attributes[$attribute] = self::usesPostgis()
            ? DB::raw("ST_GeomFromText('{$wkt}', 4326)")
            : $wkt;
    }

    /**
     * Set spatial attribute from latitude and longitude.
     */
    public function setFromCoordinates(string $attribute, float $latitude, float $longitude): void
    {
        $this->attributes[$attribute] = self::usesPostgis()
            ? DB::raw("ST_MakePoint({$longitude}, {$latitude})::geography")
            : SpatialHelper::makePoint($latitude, $longitude);
    }

    /**
     * Whether spatial columns are PostGIS-typed on this connection.
     */
    protected static function usesPostgis(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'pgsql';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Haversine distance in meters between a POINT text column and bound (?, ?) = (latitude, longitude).
     */
    protected static function haversineSql(string $column): string
    {
        $lon = sprintf("CAST(substr(%s, 7, instr(%s, ' ') - 7) AS REAL)", $column, $column);
        $lat = sprintf(
            "CAST(substr(%s, instr(%s, ' ') + 1, length(%s) - instr(%s, ' ') - 1) AS REAL)",
            $column,
            $column,
            $column,
            $column
        );

        return "2 * 6371008.8 * asin(min(1.0, sqrt(
            pow(sin((radians({$lat}) - radians(?)) / 2), 2)
            + cos(radians(?)) * cos(radians({$lat}))
                * pow(sin((radians({$lon}) - radians(?)) / 2), 2)
        )))";
    }

    /**
     * Feature ids whose stored WKT matches a geometry predicate (non-PostGIS fallback).
     *
     * @return array<int, int|string>
     */
    protected static function idsMatchingGeometry(Builder $query, string $column, string $geometry, string $predicate): array
    {
        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $ids = [];

        foreach ($model->newQuery()->whereNotNull($column)->get([$keyName, $column]) as $row) {
            $value = $row->getAttribute($column);
            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $matches = $predicate === 'within'
                    ? SpatialHelper::within($value, $geometry)
                    : SpatialHelper::intersects($value, $geometry);
            } catch (\Throwable) {
                continue;
            }

            if ($matches) {
                $ids[] = $row->getKey();
            }
        }

        return $ids;
    }
}
