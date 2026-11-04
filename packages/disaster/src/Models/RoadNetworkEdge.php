<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class RoadNetworkEdge extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_road_network_edges';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_blocked' => false,
        'free_flow_s' => 0,
    ];

    protected $casts = [
        'length_m' => 'float',
        'free_flow_s' => 'integer',
        'capacity' => 'integer',
        'is_blocked' => 'boolean',
        'props' => 'array',
    ];
}
