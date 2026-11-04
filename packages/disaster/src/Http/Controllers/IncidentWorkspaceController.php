<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Models\Layer;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\WorkspaceMember;
use Lumina\Disaster\Services\IncidentWorkspaceAccessService;
use Lumina\Disaster\Support\Enums\WorkspaceMemberRole;

class IncidentWorkspaceController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(protected IncidentWorkspaceAccessService $access) {}

    public function invite(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.command');
        abort_unless($this->access->isOwner($request->user(), $incident), 404);

        $data = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id'),
                Rule::notIn([(int) $incident->organization_id]),
            ],
            'layer_ids' => ['nullable', 'array'],
            'layer_ids.*' => ['integer', 'min:1'],
            'role' => ['nullable', Rule::enum(WorkspaceMemberRole::class)],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        $member = $this->access->inviteOrganization(
            $incident,
            $request->user(),
            (int) $data['organization_id'],
            $data['layer_ids'] ?? [],
            new \DateTimeImmutable($data['expires_at']),
            isset($data['role'])
                ? WorkspaceMemberRole::from($data['role'])
                : WorkspaceMemberRole::Collaborator,
        );

        $member->load('organization:id,name');

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'data' => [
                    'id' => $member->id,
                    'organization_id' => $member->organization_id,
                    'organization' => $member->organization?->only(['id', 'name']),
                    'role' => $member->role instanceof WorkspaceMemberRole
                        ? $member->role->value
                        : $member->role,
                    'layer_ids' => $member->sharedLayerIds(),
                    'expires_at' => $member->expires_at,
                    'revoked_at' => $member->revoked_at,
                    'is_active' => $member->isActive(),
                ],
            ], 201);
        }

        return redirect()
            ->route('disaster.incidents.show', $incident)
            ->with('success', 'Organization invited to incident workspace.');
    }

    public function revoke(Request $request, Incident $incident, WorkspaceMember $member): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.command');
        abort_unless($this->access->isOwner($request->user(), $incident), 404);

        $this->access->revokeMember($incident, $request->user(), $member);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()
            ->route('disaster.incidents.show', $incident)
            ->with('success', 'Workspace membership revoked.');
    }

    /**
     * Organizations available to invite (excludes owner).
     *
     * @return list<array{id: int, name: string}>
     */
    public static function inviteableOrganizations(int $ownerOrganizationId): array
    {
        return Organization::query()
            ->whereKeyNot($ownerOrganizationId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organization $org) => $org->only(['id', 'name']))
            ->all();
    }

    /**
     * Owner-org layers that can be included in a share scope.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function shareableLayers(int $ownerOrganizationId): array
    {
        return Layer::query()
            ->where('organization_id', $ownerOrganizationId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Layer $layer) => $layer->only(['id', 'name']))
            ->all();
    }
}
