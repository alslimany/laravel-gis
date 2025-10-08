<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait SpatialTrait
{
    /**
     * Scope to find records within a given distance from a point.
     *
     * @param  float  $distance  Distance in meters
     */
    public function scopeWithinDistance(Builder $query, string $column, float $latitude, float $longitude, float $distance): Builder
    {
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
        return $query->whereRaw(
            "ST_Within({$column}, ST_GeomFromText(?, 4326))",
            [$polygon]
        );
    }

    /**
     * Scope to find records that intersect with a geometry.
     *
     * @param  string  $geometry  WKT geometry string
     */
    public function scopeIntersects(Builder $query, string $column, string $geometry): Builder
    {
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

        return \DB::selectOne(
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

        $geojson = \DB::selectOne(
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
        $this->attributes[$attribute] = \DB::raw("ST_GeomFromText('{$wkt}', 4326)");
    }

    /**
     * Set spatial attribute from latitude and longitude.
     */
    public function setFromCoordinates(string $attribute, float $latitude, float $longitude): void
    {
        $this->attributes[$attribute] = \DB::raw("ST_MakePoint({$longitude}, {$latitude})::geography");
    }
}
