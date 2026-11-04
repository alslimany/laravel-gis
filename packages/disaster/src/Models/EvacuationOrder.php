<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;

class EvacuationOrder extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_evacuation_orders';

    protected $guarded = ['id'];

    protected $hidden = ['zone_geom'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'evacuated_count' => 0,
        'unaccounted_count' => 0,
        'is_official' => false,
    ];

    protected $casts = [
        'layer_feature_ids' => 'array',
        'corridor_ids' => 'array',
        'shelter_site_ids' => 'array',
        'status' => EvacuationOrderStatus::class,
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_official' => 'boolean',
        'estimated_population' => 'integer',
        'evacuated_count' => 'integer',
        'unaccounted_count' => 'integer',
    ];

    public function activation(): BelongsTo
    {
        return $this->belongsTo(Activation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EvacuationOrderEvent::class)->orderBy('id');
    }
}
