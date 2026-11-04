<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class Incident extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_incidents';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'opened_at' => 'datetime',
    ];

    public function operationalPeriods(): HasMany
    {
        return $this->hasMany(OperationalPeriod::class)->orderByDesc('starts_at');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(IncidentPlan::class)->orderByDesc('version');
    }

    public function currentPlan(): HasOne
    {
        return $this->hasOne(IncidentPlan::class)->latestOfMany('version');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(IncidentRole::class)->orderBy('role');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderByDesc('id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(EventLog::class)->orderByDesc('id');
    }

    public function sitreps(): HasMany
    {
        return $this->hasMany(SitRep::class)->orderByDesc('prepared_at');
    }

    public function workspace(): HasOne
    {
        return $this->hasOne(IncidentWorkspace::class);
    }

    public function resourceRequests(): HasMany
    {
        return $this->hasMany(ResourceRequest::class)->orderByDesc('id');
    }
}
