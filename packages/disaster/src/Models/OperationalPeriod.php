<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class OperationalPeriod extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_operational_periods';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(IncidentPlan::class);
    }
}
