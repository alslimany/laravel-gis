<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class GeometryColumnHelper
{
    /**
     * Resolve the geometry column name for a PostGIS table.
     */
    public static function resolve(string $tableName): string
    {
        return Cache::remember("geom_column:{$tableName}", 3600, function () use ($tableName) {
            try {
                $columns = Schema::getColumnListing($tableName);
            } catch (\Throwable) {
                return 'geom';
            }

            if (in_array('geom', $columns, true)) {
                return 'geom';
            }

            if (in_array('geometry', $columns, true)) {
                return 'geometry';
            }

            return 'geom';
        });
    }

    /**
     * Clear cached geometry column for a table.
     */
    public static function forget(string $tableName): void
    {
        Cache::forget("geom_column:{$tableName}");
    }
}
