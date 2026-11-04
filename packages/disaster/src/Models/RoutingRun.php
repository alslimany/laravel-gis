<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class RoutingRun extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $table = 'dm_routing_runs';

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'metrics' => 'array',
        'created_at' => 'datetime',
    ];

    public function evacuationOrder(): BelongsTo
    {
        return $this->belongsTo(EvacuationOrder::class);
    }
}
