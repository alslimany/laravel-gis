<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\MissingPersonStatus;

/**
 * Missing-person case. given_name / family_name are staff-only and must never
 * appear on public endpoints. Prefer display_name_redacted for shared UI.
 */
class MissingPerson extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_missing_persons';

    protected $guarded = ['id'];

    protected $hidden = [
        'given_name',
        'family_name',
        'geom',
        'notes',
    ];

    protected $casts = [
        'status' => MissingPersonStatus::class,
        'age_years' => 'integer',
        'last_seen_at' => 'datetime',
        'retained_until' => 'date',
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(PersonReport::class, 'missing_person_id');
    }
}
