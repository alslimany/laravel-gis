<?php

namespace Lumina\EarlyWarning\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Org subscription to a global basin (ADR D12). */
class Subscription extends Model
{
    protected $table = 'ew_subscriptions';

    protected $guarded = ['id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function basin(): BelongsTo
    {
        return $this->belongsTo(Basin::class, 'basin_code', 'basin_code');
    }
}
