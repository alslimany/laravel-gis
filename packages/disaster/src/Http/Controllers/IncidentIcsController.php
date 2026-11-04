<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\EventLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentPlan;
use Lumina\Disaster\Models\IncidentRole;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Services\IncidentLifecycleService;
use Lumina\Disaster\Services\IncidentWorkspaceAccessService;
use Lumina\Disaster\Services\SitRepExporter;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\TaskPriority;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Support\Pdf\PdfRenderer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentIcsController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(
        protected IncidentLifecycleService $lifecycle,
        protected IncidentWorkspaceAccessService $workspaceAccess,
    ) {}

    public function storeOperationalPeriod(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'objectives' => ['nullable', 'string', 'max:10000'],
        ]);

        $period = $this->lifecycle->createOperationalPeriod($incident, $data, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $period], 201);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Operational period created.');
    }

    public function storePlan(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);

        $data = $this->validatedPlan($request, $incident);
        $plan = $this->lifecycle->draftPlan($incident, $data, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $plan], 201);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'IAP drafted.');
    }

    public function updatePlan(Request $request, Incident $incident, IncidentPlan $plan): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);
        $this->assertPlan($incident, $plan);

        $data = $this->validatedPlan($request, $incident, updating: true);
        $plan = $this->lifecycle->updateDraftPlan($plan, $data, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $plan]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'IAP updated.');
    }

    public function submitPlan(Request $request, Incident $incident, IncidentPlan $plan): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);
        $this->assertPlan($incident, $plan);

        $plan = $this->lifecycle->submitPlanForApproval($plan, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $plan]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'IAP submitted for approval.');
    }

    public function approvePlan(Request $request, Incident $incident, IncidentPlan $plan): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.command');
        $this->assertOrg($request, $incident);
        $this->assertPlan($incident, $plan);

        $plan = $this->lifecycle->approvePlan($plan, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $plan]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'IAP approved.');
    }

    public function storeRole(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.command');
        $this->assertOrg($request, $incident);

        $data = $request->validate([
            'role' => ['required', Rule::enum(IncidentRoleType::class)],
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('organization_id', $incident->organization_id)),
            ],
        ]);

        $assignee = User::query()->findOrFail($data['user_id']);
        $role = IncidentRoleType::from($data['role']);
        $assignment = $this->lifecycle->assignRole($incident, $role, $assignee, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $assignment->load('user:id,name,email')], 201);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Role assigned.');
    }

    public function destroyRole(Request $request, Incident $incident, IncidentRole $role): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.command');
        $this->assertOrg($request, $incident);
        abort_unless((int) $role->incident_id === (int) $incident->id, 404);

        $this->lifecycle->removeRole($role, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Role removed.');
    }

    public function storeTask(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);

        $data = $this->validatedTask($request, $incident);
        $task = $this->lifecycle->createTask($incident, $data, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->presentTask($task)], 201);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Task created.');
    }

    public function updateTask(Request $request, Incident $incident, Task $task): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        abort_unless((int) $task->incident_id === (int) $incident->id, 404);

        $user = $request->user();
        if ($this->workspaceAccess->isOwner($user, $incident)) {
            $this->assertOrg($request, $incident);
        } else {
            abort_unless($this->workspaceAccess->canUpdateAssignedTask($user, $incident, $task), 404);
            $this->workspaceAccess->authorizeIncidentAccess($user, $incident, 'task_update');
        }

        $data = $this->validatedTask($request, $incident, updating: true);
        $task = $this->lifecycle->updateTask($task, $data, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->presentTask($task)]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Task updated.');
    }

    public function destroyTask(Request $request, Incident $incident, Task $task): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);
        abort_unless((int) $task->incident_id === (int) $incident->id, 404);

        $taskId = $task->id;
        $task->delete();

        EventLog::record(
            $incident,
            'task_deleted',
            'Task #'.$taskId.' deleted',
            $request->user()->getKey(),
            ['task_id' => $taskId],
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Task deleted.');
    }

    public function sitRepPrefill(Request $request, Incident $incident): JsonResponse
    {
        $this->assertOrg($request, $incident);

        return response()->json(['data' => $this->lifecycle->sitRepPrefill($incident)]);
    }

    public function storeSitRep(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);

        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:20000'],
            'casualties' => ['nullable', 'array'],
            'resources' => ['nullable', 'array'],
            'lifelines_status' => ['nullable', 'array'],
            'next_steps' => ['nullable', 'string', 'max:10000'],
        ]);

        $sitrep = $this->lifecycle->createSitRep($incident, $data, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $sitrep], 201);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'SitRep saved.');
    }

    public function exportSitRepJson(Request $request, Incident $incident, SitRep $sitrep): JsonResponse|StreamedResponse
    {
        $this->assertOrg($request, $incident);
        abort_unless((int) $sitrep->incident_id === (int) $incident->id, 404);

        $payload = SitRepExporter::forSitRep($sitrep);
        $filename = 'sitrep-'.$incident->external_id.'-'.$sitrep->id.'.json';

        return response()->streamDownload(
            static function () use ($payload): void {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function exportSitRepPdf(Request $request, Incident $incident, SitRep $sitrep): HttpResponse|RedirectResponse
    {
        $this->assertOrg($request, $incident);
        abort_unless((int) $sitrep->incident_id === (int) $incident->id, 404);

        $payload = SitRepExporter::forSitRep($sitrep);
        $filename = 'sitrep-'.$incident->external_id.'-'.$sitrep->id.'.pdf';

        return PdfRenderer::download('disaster::sitrep', [
            'incident' => $incident,
            'sitrep' => $sitrep,
            'payload' => $payload,
        ], $filename);
    }

    public function close(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');
        $this->assertOrg($request, $incident);

        $incident = $this->lifecycle->closeIncident($incident, $request->user());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $incident]);
        }

        return redirect()->route('disaster.incidents.show', $incident)->with('success', 'Incident closed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPlan(Request $request, Incident $incident, bool $updating = false): array
    {
        return $request->validate([
            'operational_period_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_operational_periods', 'id')->where(
                    fn ($q) => $q->where('incident_id', $incident->id)->where('organization_id', $incident->organization_id)
                ),
            ],
            'objectives' => [$updating ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:10000'],
            'strategies' => ['nullable', 'string', 'max:10000'],
            'safety_message' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedTask(Request $request, Incident $incident, bool $updating = false): array
    {
        return $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'assigned_avl_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_avl_units', 'id')->where(
                    fn ($q) => $q->where('organization_id', $incident->organization_id)
                ),
            ],
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('organization_id', $incident->organization_id)),
            ],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string', 'max:10000'],
            'location' => ['nullable', 'array'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentTask(Task $task): array
    {
        $task->loadMissing('assignedAvlUnit');
        $row = $task->only([
            'id', 'title', 'assigned_avl_unit_id', 'assigned_user_id', 'status', 'priority', 'due_at', 'location', 'result',
        ]);
        $row['status'] = $task->status instanceof TaskStatus ? $task->status->value : $task->status;
        $row['priority'] = $task->priority instanceof TaskPriority ? $task->priority->value : $task->priority;
        $unit = $task->assignedAvlUnit;
        $row['avl_unit'] = $unit instanceof AvlUnit
            ? $unit->only(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at'])
            : null;

        return $row;
    }

    protected function assertOrg(Request $request, Incident $incident): void
    {
        abort_unless((int) $incident->organization_id === (int) $request->user()->organization_id, 404);
    }

    protected function assertPlan(Incident $incident, IncidentPlan $plan): void
    {
        abort_unless((int) $plan->incident_id === (int) $incident->id, 404);
        abort_unless((int) $plan->organization_id === (int) $incident->organization_id, 404);
    }
}
