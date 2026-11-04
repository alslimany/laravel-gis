<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;

class IncidentPlan extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_incident_plans';

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
    ];

    protected $casts = [
        'status' => IncidentPlanStatus::class,
        'approved_at' => 'datetime',
        'version' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function operationalPeriod(): BelongsTo
    {
        return $this->belongsTo(OperationalPeriod::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
