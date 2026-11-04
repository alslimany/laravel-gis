<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class EvacCorridor extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_evac_corridors';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'manual',
    ];

    protected $casts = [
        'props' => 'array',
        'distance_m' => 'float',
        'travel_time_s' => 'integer',
        'computed_at' => 'datetime',
    ];

    public function shelterSite(): BelongsTo
    {
        return $this->belongsTo(CriticalSite::class, 'shelter_site_id');
    }

    public function evacuationOrder(): BelongsTo
    {
        return $this->belongsTo(EvacuationOrder::class);
    }
}
