<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A layer's `table_name` can be a real table or a Postgres view (SQL-view
 * layers). Use this instead of Schema::hasTable() when reading layer data.
 */
class LayerTable
{
    public static function exists(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        if (Schema::hasTable($name)) {
            return true;
        }

        try {
            return Schema::hasView($name);
        } catch (Throwable) {
            return false;
        }
    }

    public static function isView(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        try {
            return Schema::hasView($name);
        } catch (Throwable) {
            return false;
        }
    }
}
