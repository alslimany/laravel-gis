<?php

namespace Lumina\Disaster\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail for cross-organization workspace access and grant changes.
 */
class WorkspaceAccessLog extends Model
{
    protected $table = 'dm_workspace_access_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('dm_workspace_access_log is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('dm_workspace_access_log is append-only and cannot be deleted.');
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(IncidentWorkspace::class, 'workspace_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'actor_organization_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        IncidentWorkspace $workspace,
        string $action,
        int $actorOrganizationId,
        ?int $actorUserId = null,
        array $payload = [],
    ): self {
        return static::query()->create([
            'workspace_id' => $workspace->id,
            'incident_id' => $workspace->incident_id,
            'actor_organization_id' => $actorOrganizationId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
