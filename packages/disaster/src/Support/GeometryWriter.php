<?php

namespace Lumina\Disaster\Support;

use App\Helpers\SpatialHelper;
use Illuminate\Support\Facades\DB;

final class GeometryWriter
{
    public static function pointFromCoordinates(float $latitude, float $longitude): mixed
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::raw("ST_SetSRID(ST_MakePoint({$longitude}, {$latitude}), 4326)");
        }

        return SpatialHelper::makePoint($latitude, $longitude);
    }

    public static function pointFromGeoJson(array $geoJson): mixed
    {
        $wkt = SpatialHelper::geoJsonToWkt($geoJson);
        if ($wkt === null) {
            return null;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::raw("ST_GeomFromText('{$wkt}', 4326)");
        }

        return $wkt;
    }

    public static function lineFromGeoJson(array $geoJson): mixed
    {
        return self::pointFromGeoJson($geoJson);
    }

    public static function polygonFromGeoJson(array $geoJson): mixed
    {
        return self::pointFromGeoJson($geoJson);
    }
}
