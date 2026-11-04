<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\Habitability;

class DamagePoint extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_damage_points';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $attributes = [
        'review_status' => 'submitted',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'props' => 'array',
        'hazards_present' => 'array',
        'urgent_needs' => 'array',
        'people_affected' => 'integer',
        'damage_level' => DamageLevel::class,
        'habitability' => Habitability::class,
        'review_status' => DamageReviewStatus::class,
    ];

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function lifelineComponent(): BelongsTo
    {
        return $this->belongsTo(LifelineComponent::class, 'lifeline_component_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DamagePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('review_status', DamageReviewStatus::Verified->value);
    }

    public function isOfficial(): bool
    {
        $status = $this->review_status instanceof DamageReviewStatus
            ? $this->review_status
            : DamageReviewStatus::tryFrom((string) $this->review_status);

        return $status?->isOfficial() ?? false;
    }

    public function zoneKey(): string
    {
        $props = is_array($this->props) ? $this->props : [];
        $zone = $props['zone'] ?? $props['neighborhood'] ?? null;

        if (is_string($zone) && trim($zone) !== '') {
            return trim($zone);
        }

        return 'unassigned';
    }
}
