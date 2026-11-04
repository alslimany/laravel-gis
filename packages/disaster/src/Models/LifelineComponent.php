<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\Lifeline;

class LifelineComponent extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_lifeline_components';

    protected $guarded = ['id'];

    protected $casts = [
        'lifeline' => Lifeline::class,
        'linked_asset_id' => 'integer',
        'layer_id' => 'integer',
        'layer_feature_id' => 'integer',
    ];

    public function statuses(): HasMany
    {
        return $this->hasMany(LifelineStatus::class, 'component_id')->orderByDesc('reported_at');
    }

    public function latestStatus(): HasOne
    {
        return $this->hasOne(LifelineStatus::class, 'component_id')->latestOfMany('reported_at');
    }
}
