<?php

namespace Lumina\Disaster\Services;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentWorkspace;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Models\WorkspaceAccessLog;
use Lumina\Disaster\Models\WorkspaceMember;
use Lumina\Disaster\Support\Enums\WorkspaceMemberRole;
use Lumina\Disaster\Support\Enums\WorkspaceStatus;

/**
 * Single gate for plugin-level cross-organization incident workspace access.
 *
 * Core organization_id tenancy is unchanged: grants live only in dm_workspace_* tables.
 */
class IncidentWorkspaceAccessService
{
    public function isOwner(User $user, Incident $incident): bool
    {
        return $user->organization_id !== null
            && (int) $user->organization_id === (int) $incident->organization_id;
    }

    public function activeMembership(User $user, Incident $incident): ?WorkspaceMember
    {
        if ($user->organization_id === null) {
            return null;
        }

        $workspace = $this->workspaceForIncident($incident);
        if ($workspace === null || ! $workspace->isActive()) {
            return null;
        }

        return WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->active()
            ->where(function (Builder $query) use ($user): void {
                $query->where('organization_id', $user->organization_id)
                    ->orWhere('user_id', $user->id);
            })
            ->orderByDesc('id')
            ->first();
    }

    public function canAccessIncident(User $user, Incident $incident): bool
    {
        if ($this->isOwner($user, $incident)) {
            return true;
        }

        return $this->activeMembership($user, $incident) !== null;
    }

    /**
     * Authorize a cross-org (or owner) incident read and audit invitee access.
     */
    public function authorizeIncidentAccess(User $user, Incident $incident, string $action = 'incident_view'): void
    {
        if ($this->isOwner($user, $incident)) {
            return;
        }

        $membership = $this->activeMembership($user, $incident);
        abort_unless($membership !== null, 404);

        $workspace = $membership->workspace ?? $this->workspaceForIncident($incident);
        if ($workspace !== null) {
            WorkspaceAccessLog::record(
                $workspace,
                $action,
                (int) $user->organization_id,
                (int) $user->id,
                [
                    'member_id' => $membership->id,
                    'role' => $membership->role instanceof WorkspaceMemberRole
                        ? $membership->role->value
                        : $membership->role,
                ],
            );
        }
    }

    public function canViewSharedLayer(User $user, Incident $incident, int $layerId): bool
    {
        if ($this->isOwner($user, $incident)) {
            return Layer::query()
                ->whereKey($layerId)
                ->where('organization_id', $incident->organization_id)
                ->exists();
        }

        $membership = $this->activeMembership($user, $incident);
        if ($membership === null) {
            return false;
        }

        return in_array($layerId, $membership->sharedLayerIds(), true);
    }

    public function canUpdateAssignedTask(User $user, Incident $incident, Task $task): bool
    {
        if ((int) $task->incident_id !== (int) $incident->id) {
            return false;
        }

        if ($this->isOwner($user, $incident)) {
            return true;
        }

        $membership = $this->activeMembership($user, $incident);
        if ($membership === null) {
            return false;
        }

        $role = $membership->role instanceof WorkspaceMemberRole
            ? $membership->role
            : WorkspaceMemberRole::tryFrom((string) $membership->role);

        if ($role !== WorkspaceMemberRole::Collaborator) {
            return false;
        }

        return $this->taskAssignedToInvitee($task, $user, $membership);
    }

    /**
     * @return Collection<int, Task>
     */
    public function visibleTasks(User $user, Incident $incident, Collection $tasks): Collection
    {
        if ($this->isOwner($user, $incident)) {
            return $tasks->values();
        }

        $membership = $this->activeMembership($user, $incident);
        if ($membership === null) {
            return collect();
        }

        return $tasks
            ->filter(fn (Task $task) => $this->taskAssignedToInvitee($task, $user, $membership))
            ->values();
    }

    /**
     * Shared incidents visible to the invitee's organization (not owned by them).
     *
     * @return Collection<int, Incident>
     */
    public function sharedIncidentsForOrganization(int $organizationId): Collection
    {
        $workspaceIds = WorkspaceMember::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->pluck('workspace_id');

        if ($workspaceIds->isEmpty()) {
            return collect();
        }

        return Incident::query()
            ->whereIn('id', IncidentWorkspace::query()
                ->whereIn('id', $workspaceIds)
                ->where('status', WorkspaceStatus::Active->value)
                ->where('owner_organization_id', '!=', $organizationId)
                ->select('incident_id'))
            ->orderByDesc('opened_at')
            ->get();
    }

    public function ensureWorkspace(Incident $incident): IncidentWorkspace
    {
        return IncidentWorkspace::query()->firstOrCreate(
            ['incident_id' => $incident->id],
            [
                'owner_organization_id' => $incident->organization_id,
                'status' => WorkspaceStatus::Active,
            ],
        );
    }

    /**
     * @param  list<int>  $layerIds
     */
    public function inviteOrganization(
        Incident $incident,
        User $actor,
        int $inviteeOrganizationId,
        array $layerIds,
        \DateTimeInterface $expiresAt,
        WorkspaceMemberRole $role = WorkspaceMemberRole::Collaborator,
    ): WorkspaceMember {
        if (! $this->isOwner($actor, $incident)) {
            abort(403);
        }

        if ($inviteeOrganizationId === (int) $incident->organization_id) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cannot invite the owner organization.',
            ]);
        }

        abort_unless(Organization::query()->whereKey($inviteeOrganizationId)->exists(), 422);

        $validatedLayerIds = $this->validateOwnerLayerIds($incident, $layerIds);
        $workspace = $this->ensureWorkspace($incident);

        return DB::transaction(function () use (
            $workspace,
            $actor,
            $inviteeOrganizationId,
            $validatedLayerIds,
            $expiresAt,
            $role,
            $incident,
        ): WorkspaceMember {
            $prior = WorkspaceMember::query()
                ->where('workspace_id', $workspace->id)
                ->where('organization_id', $inviteeOrganizationId)
                ->whereNull('revoked_at')
                ->get();

            foreach ($prior as $existing) {
                $existing->forceFill(['revoked_at' => now()])->save();
                WorkspaceAccessLog::record(
                    $workspace,
                    'member_superseded',
                    (int) $actor->organization_id,
                    (int) $actor->id,
                    ['member_id' => $existing->id],
                );
            }

            $member = WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'organization_id' => $inviteeOrganizationId,
                'user_id' => null,
                'role' => $role,
                'scope' => ['layer_ids' => $validatedLayerIds],
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'invited_by' => $actor->id,
            ]);

            WorkspaceAccessLog::record(
                $workspace,
                'member_invited',
                (int) $actor->organization_id,
                (int) $actor->id,
                [
                    'member_id' => $member->id,
                    'invitee_organization_id' => $inviteeOrganizationId,
                    'layer_ids' => $validatedLayerIds,
                    'role' => $role->value,
                    'expires_at' => $member->expires_at?->toIso8601String(),
                    'incident_id' => $incident->id,
                ],
            );

            return $member;
        });
    }

    public function revokeMember(Incident $incident, User $actor, WorkspaceMember $member): WorkspaceMember
    {
        if (! $this->isOwner($actor, $incident)) {
            abort(403);
        }

        $workspace = $this->ensureWorkspace($incident);
        abort_unless((int) $member->workspace_id === (int) $workspace->id, 404);

        if ($member->revoked_at === null) {
            $member->forceFill(['revoked_at' => now()])->save();
            WorkspaceAccessLog::record(
                $workspace,
                'member_revoked',
                (int) $actor->organization_id,
                (int) $actor->id,
                [
                    'member_id' => $member->id,
                    'invitee_organization_id' => $member->organization_id,
                ],
            );
        }

        return $member->refresh();
    }

    /**
     * Mark expired memberships as revoked. Access checks also honor expires_at immediately.
     */
    public function expireMembers(): int
    {
        $expired = WorkspaceMember::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expired as $member) {
            $member->forceFill(['revoked_at' => now()])->save();
            $workspace = $member->workspace;
            if ($workspace !== null) {
                WorkspaceAccessLog::record(
                    $workspace,
                    'member_expired',
                    (int) ($member->organization_id ?? $workspace->owner_organization_id),
                    null,
                    ['member_id' => $member->id],
                );
            }
            $count++;
        }

        return $count;
    }

    public function workspaceForIncident(Incident $incident): ?IncidentWorkspace
    {
        return IncidentWorkspace::query()
            ->where('incident_id', $incident->id)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentMembers(IncidentWorkspace $workspace): array
    {
        return $workspace->members()
            ->with('organization:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(function (WorkspaceMember $member): array {
                return [
                    'id' => $member->id,
                    'organization_id' => $member->organization_id,
                    'organization' => $member->organization?->only(['id', 'name']),
                    'user_id' => $member->user_id,
                    'role' => $member->role instanceof WorkspaceMemberRole
                        ? $member->role->value
                        : $member->role,
                    'scope' => $member->scope,
                    'layer_ids' => $member->sharedLayerIds(),
                    'expires_at' => $member->expires_at,
                    'revoked_at' => $member->revoked_at,
                    'is_active' => $member->isActive(),
                    'revoke_url' => route('disaster.incidents.workspace.members.revoke', [
                        'incident' => $workspace->incident_id,
                        'member' => $member->id,
                    ]),
                ];
            })
            ->all();
    }

    protected function taskAssignedToInvitee(Task $task, User $user, WorkspaceMember $membership): bool
    {
        if ($task->assigned_user_id !== null && (int) $task->assigned_user_id === (int) $user->id) {
            return true;
        }

        if ($membership->organization_id === null || $task->assigned_user_id === null) {
            return false;
        }

        return User::query()
            ->whereKey($task->assigned_user_id)
            ->where('organization_id', $membership->organization_id)
            ->exists();
    }

    /**
     * @param  list<int|string>  $layerIds
     * @return list<int>
     */
    protected function validateOwnerLayerIds(Incident $incident, array $layerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $layerIds)));
        if ($ids === []) {
            return [];
        }

        $owned = Layer::query()
            ->where('organization_id', $incident->organization_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff($ids, $owned));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'layer_ids' => 'Layer IDs must belong to the owner organization: '.implode(', ', $missing),
            ]);
        }

        return $owned;
    }
}
