<?php

namespace Lumina\Disaster\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Support\Enums\WorkspaceStatus;

class IncidentWorkspace extends Model
{
    protected $table = 'dm_incident_workspaces';

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'status' => WorkspaceStatus::class,
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class, 'workspace_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(WorkspaceAccessLog::class, 'workspace_id');
    }

    public function isActive(): bool
    {
        return $this->status === WorkspaceStatus::Active;
    }
}
