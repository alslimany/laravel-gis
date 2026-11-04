<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;

class InfrastructureCondition extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_infrastructure_conditions';

    protected $guarded = ['id'];

    protected $casts = [
        'condition_grade' => InfrastructureConditionGrade::class,
        'last_inspection_at' => 'datetime',
        'next_inspection_due' => 'datetime',
        'attachments' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(CriticalSite::class, 'site_id');
    }

    /**
     * Inspections whose next due date is in the past (or due now).
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('next_inspection_due')
            ->where('next_inspection_due', '<=', now());
    }
}
