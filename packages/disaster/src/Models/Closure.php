<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class Closure extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_closures';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];
}
