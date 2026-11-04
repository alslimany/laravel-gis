<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class AvlUnit extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_avl_units';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'last_ping_at' => 'datetime',
        'extras' => 'array',
        'speed_kmh' => 'float',
        'heading_deg' => 'float',
    ];
}
