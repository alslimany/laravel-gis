<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class AvlPing extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_avl_pings';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'extras' => 'array',
    ];
}
