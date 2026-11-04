<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class CriticalSite extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_critical_sites';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $attributes = [
        'current_occupancy' => 0,
    ];

    protected $casts = [
        'capacity' => 'integer',
        'current_occupancy' => 'integer',
        'remaining_capacity' => 'integer',
        'capacity_mcm' => 'float',
        'height_m' => 'float',
        'spillway_capacity' => 'float',
        'year_built' => 'integer',
        'downstream_zone_layer_id' => 'integer',
    ];

    public function conditions(): HasMany
    {
        return $this->hasMany(InfrastructureCondition::class, 'site_id');
    }

    public function shelterCheckins(): HasMany
    {
        return $this->hasMany(ShelterCheckin::class, 'shelter_site_id');
    }
}
