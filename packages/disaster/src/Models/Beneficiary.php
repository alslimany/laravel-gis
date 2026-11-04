<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

/**
 * Minimal household registry row. Prefer household_code over real names.
 * contact_ref is an opaque token — never treat as a phone number in API payloads.
 */
class Beneficiary extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_beneficiaries';

    protected $guarded = ['id'];

    protected $hidden = [
        'contact_ref',
    ];

    protected $casts = [
        'household_size' => 'integer',
        'needs' => 'array',
        'props' => 'array',
        'retained_until' => 'date',
    ];

    public function shelterSite(): BelongsTo
    {
        return $this->belongsTo(CriticalSite::class, 'shelter_site_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(ShelterCheckin::class, 'beneficiary_id');
    }
}
