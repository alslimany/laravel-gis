<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class EvacuationOrderEvent extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $table = 'dm_evacuation_order_events';

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function evacuationOrder(): BelongsTo
    {
        return $this->belongsTo(EvacuationOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
