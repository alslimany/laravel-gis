<?php

namespace Lumina\EarlyWarning\Models;

use Illuminate\Database\Eloquent\Model;

/** Global basin (no organization scope). Geometry column `geom` is written by LinkBasinsCommand. */
class Basin extends Model
{
    protected $table = 'ew_basins';

    protected $guarded = ['id', 'geom'];

    protected $hidden = ['geom'];

    protected $casts = [
        'props' => 'array',
        'processed_at' => 'datetime',
    ];
}
