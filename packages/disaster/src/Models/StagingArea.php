<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class StagingArea extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_staging_areas';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'fuel_availability' => 'boolean',
    ];
}
