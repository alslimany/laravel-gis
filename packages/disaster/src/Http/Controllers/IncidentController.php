<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentPlan;
use Lumina\Disaster\Models\IncidentRole;
use Lumina\Disaster\Models\OperationalPeriod;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Models\WorkspaceMember;
use Lumina\Disaster\Services\IncidentLifecycleService;
use Lumina\Disaster\Services\IncidentWorkspaceAccessService;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\TaskPriority;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Support\Enums\WorkspaceMemberRole;
use Lumina\Disaster\Support\Enums\WorkspaceStatus;
use Lumina\Disaster\Support\EventLogLabel;
use Lumina\Disaster\Support\GeometryReader;
use Lumina\Disaster\Support\GeometryWriter;

class IncidentController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(
        protected IncidentLifecycleService $lifecycle,
        protected IncidentWorkspaceAccessService $workspaceAccess,
    ) {}

    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $incidents = Incident::query()
            ->forOrganization($organizationId)
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (Incident $incident) => $this->presentListRow($incident))
            ->all();

        $sharedIncidents = $this->workspaceAccess
            ->sharedIncidentsForOrganization($organizationId)
            ->map(function (Incident $incident) {
                $row = $this->presentListRow($incident);
                $row['shared'] = true;
                $row['owner_organization_id'] = $incident->organization_id;

                return $row;
            })
            ->all();

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json([
                'data' => $incidents,
                'shared' => $sharedIncidents,
            ]);
        }

        return Inertia::render('Disaster/Incidents', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'incidents' => $incidents,
            'shared_incidents' => $sharedIncidents,
            'store_url' => route('disaster.incidents.store'),
            'status_options' => ['open', 'closed', 'monitoring'],
        ]);
    }

    public function show(Request $request, Incident $incident): Response|JsonResponse
    {
        $user = $request->user();
        $this->workspaceAccess->authorizeIncidentAccess($user, $incident);
        $isOwner = $this->workspaceAccess->isOwner($user, $incident);
        $membership = $isOwner ? null : $this->workspaceAccess->activeMembership($user, $incident);

        $incident->load([
            'operationalPeriods',
            'plans',
            'roles.user:id,name,email',
            'tasks.assignedAvlUnit',
            'tasks.assignedUser:id,name,email',
            'eventLogs' => fn ($q) => $q->orderByDesc('id')->limit(100),
            'sitreps' => fn ($q) => $q->orderByDesc('id')->limit(20),
            'workspace.members.organization:id,name',
        ]);

        $organizationId = (int) $incident->organization_id;
        $avlUnits = $isOwner
            ? AvlUnit::query()
                ->forOrganization($organizationId)
                ->orderBy('unit_id')
                ->get(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at'])
            : collect();

        $orgUsers = $isOwner
            ? User::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : collect();

        $detail = $isOwner
            ? $this->presentDetail($incident)
            : $this->presentSharedDetail($incident, $user, $membership);

        $workspacePayload = null;
        if ($isOwner) {
            $workspace = $this->workspaceAccess->workspaceForIncident($incident);
            $workspacePayload = [
                'id' => $workspace?->id,
                'status' => $workspace?->status instanceof WorkspaceStatus
                    ? $workspace->status->value
                    : ($workspace?->status ?? 'active'),
                'members' => $workspace
                    ? $this->workspaceAccess->presentMembers($workspace->loadMissing('members.organization:id,name'))
                    : [],
                'invite_url' => route('disaster.incidents.workspace.invite', $incident),
                'inviteable_organizations' => IncidentWorkspaceController::inviteableOrganizations($organizationId),
                'shareable_layers' => IncidentWorkspaceController::shareableLayers($organizationId),
                'role_options' => ConsoleAuth::enumValues(WorkspaceMemberRole::class),
            ];
        } elseif ($membership !== null) {
            $workspacePayload = [
                'id' => $membership->workspace_id,
                'shared' => true,
                'role' => $membership->role instanceof WorkspaceMemberRole
                    ? $membership->role->value
                    : $membership->role,
                'layer_ids' => $membership->sharedLayerIds(),
                'expires_at' => $membership->expires_at,
            ];
        }

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json([
                'data' => $detail,
                'avl_units' => $avlUnits,
                'org_users' => $orgUsers,
                'sitrep_prefill' => $isOwner ? $this->lifecycle->sitRepPrefill($incident) : null,
                'workspace' => $workspacePayload,
                'is_owner' => $isOwner,
            ]);
        }

        return Inertia::render('Disaster/IncidentShow', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'incident' => $detail,
            'avl_units' => $avlUnits,
            'org_users' => $orgUsers,
            'sitrep_prefill' => $isOwner ? $this->lifecycle->sitRepPrefill($incident) : [
                'summary' => '',
                'casualties' => [],
                'resources' => [],
                'lifelines_status' => [],
                'next_steps' => '',
            ],
            'role_options' => ConsoleAuth::enumValues(IncidentRoleType::class),
            'task_status_options' => ConsoleAuth::enumValues(TaskStatus::class),
            'task_priority_options' => ConsoleAuth::enumValues(TaskPriority::class),
            'plan_status_options' => ConsoleAuth::enumValues(IncidentPlanStatus::class),
            'index_url' => route('disaster.incidents.index'),
            'is_owner' => $isOwner,
            'workspace' => $workspacePayload,
            'urls' => [
                'close' => route('disaster.incidents.close', $incident),
                'operational_periods' => route('disaster.incidents.operational-periods.store', $incident),
                'plans' => route('disaster.incidents.plans.store', $incident),
                'roles' => route('disaster.incidents.roles.store', $incident),
                'tasks' => route('disaster.incidents.tasks.store', $incident),
                'sitreps' => route('disaster.incidents.sitreps.store', $incident),
                'sitrep_prefill' => route('disaster.incidents.sitreps.prefill', $incident),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $incident = Incident::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'source' => $data['source'] ?? 'console',
            'opened_at' => $data['opened_at'] ?? now(),
            'geom' => isset($data['latitude'], $data['longitude'])
                ? GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude'])
                : null,
        ]);

        $this->lifecycle->logOpened($incident, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $incident], 201);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Incident created.');
    }

    public function update(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        abort_unless($this->workspaceAccess->isOwner($request->user(), $incident), 404);
        $data = $this->validated($request, updating: true);
        $incident->fill($data);
        if (isset($data['latitude'], $data['longitude'])) {
            $incident->geom = GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude']);
        }
        $incident->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $incident]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Incident updated.');
    }

    public function destroy(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        abort_unless($this->workspaceAccess->isOwner($request->user(), $incident), 404);
        $incident->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.incidents.index')->with('success', 'Incident deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'external_id' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:64'],
            'incident_type' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', 'max:32'],
            'opened_at' => ['nullable', 'date'],
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentListRow(Incident $incident): array
    {
        $row = $incident->only([
            'id', 'external_id', 'title', 'status', 'priority', 'incident_type', 'opened_at', 'notes',
        ]);
        $coords = GeometryReader::pointCoordinates($incident->geom);
        if ($coords !== null) {
            $row['latitude'] = $coords['latitude'];
            $row['longitude'] = $coords['longitude'];
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDetail(Incident $incident): array
    {
        $row = $this->presentListRow($incident);
        $row['operational_periods'] = $incident->operationalPeriods->map(
            fn (OperationalPeriod $period) => $period->only(['id', 'starts_at', 'ends_at', 'objectives']),
        )->all();
        $row['plans'] = $incident->plans->map(fn (IncidentPlan $plan) => $this->presentPlan($plan))->all();
        $row['current_plan'] = $incident->plans->sortByDesc('version')->first()
            ? $this->presentPlan($incident->plans->sortByDesc('version')->first())
            : null;
        $row['roles'] = $incident->roles->map(function (IncidentRole $role) {
            return [
                'id' => $role->id,
                'role' => $role->role instanceof IncidentRoleType ? $role->role->value : $role->role,
                'user_id' => $role->user_id,
                'user' => $role->user?->only(['id', 'name', 'email']),
            ];
        })->all();
        $row['tasks'] = $incident->tasks->map(fn (Task $task) => $this->presentTask($task))->all();
        $row['event_log'] = $incident->eventLogs->map(fn ($log) => [
            'id' => $log->id,
            'event_type' => $log->event_type,
            'event_label' => EventLogLabel::type((string) $log->event_type),
            'body' => EventLogLabel::body((string) $log->event_type, $log->body, is_array($log->payload) ? $log->payload : null),
            'actor_user_id' => $log->actor_user_id,
            'payload' => $log->payload,
            'created_at' => $log->created_at,
        ])->all();
        $row['sitreps'] = $incident->sitreps->map(fn (SitRep $sitrep) => [
            'id' => $sitrep->id,
            'summary' => $sitrep->summary,
            'prepared_by' => $sitrep->prepared_by,
            'prepared_at' => $sitrep->prepared_at,
            'export_json_url' => route('disaster.incidents.sitreps.export.json', [$incident, $sitrep]),
            'export_pdf_url' => route('disaster.incidents.sitreps.export.pdf', [$incident, $sitrep]),
        ])->all();

        return $row;
    }

    /**
     * Invitee view: shared incident fields, selected layer IDs, and tasks assigned to them only.
     *
     * @return array<string, mixed>
     */
    protected function presentSharedDetail(Incident $incident, User $user, ?WorkspaceMember $membership): array
    {
        $row = $this->presentListRow($incident);
        $row['shared'] = true;
        $row['owner_organization_id'] = $incident->organization_id;
        $row['shared_layer_ids'] = $membership?->sharedLayerIds() ?? [];
        $row['operational_periods'] = [];
        $row['plans'] = [];
        $row['current_plan'] = null;
        $row['roles'] = [];
        $row['event_log'] = [];
        $row['sitreps'] = [];
        $row['tasks'] = $this->workspaceAccess
            ->visibleTasks($user, $incident, $incident->tasks)
            ->map(fn (Task $task) => $this->presentTask($task))
            ->all();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentPlan(IncidentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'operational_period_id' => $plan->operational_period_id,
            'objectives' => $plan->objectives,
            'strategies' => $plan->strategies,
            'safety_message' => $plan->safety_message,
            'approved_by' => $plan->approved_by,
            'approved_at' => $plan->approved_at,
            'version' => $plan->version,
            'status' => $plan->status instanceof IncidentPlanStatus ? $plan->status->value : $plan->status,
            'submit_url' => route('disaster.incidents.plans.submit', [$plan->incident_id, $plan]),
            'approve_url' => route('disaster.incidents.plans.approve', [$plan->incident_id, $plan]),
            'update_url' => route('disaster.incidents.plans.update', [$plan->incident_id, $plan]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentTask(Task $task): array
    {
        $row = $task->only([
            'id', 'title', 'assigned_avl_unit_id', 'assigned_user_id', 'status', 'priority', 'due_at', 'location', 'result',
        ]);
        $row['status'] = $task->status instanceof TaskStatus ? $task->status->value : $task->status;
        $row['priority'] = $task->priority instanceof TaskPriority ? $task->priority->value : $task->priority;
        $unit = $task->assignedAvlUnit;
        $row['avl_unit'] = $unit instanceof AvlUnit
            ? $unit->only(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at'])
            : null;
        $row['assigned_user'] = $task->assignedUser?->only(['id', 'name', 'email']);
        $row['update_url'] = route('disaster.incidents.tasks.update', [$task->incident_id, $task]);
        $row['destroy_url'] = route('disaster.incidents.tasks.destroy', [$task->incident_id, $task]);

        return $row;
    }
}
