<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EventLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentPlan;
use Lumina\Disaster\Models\IncidentRole;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Models\OperationalPeriod;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\TaskPriority;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Support\GeometryWriter;

class IncidentLifecycleService
{
    public function logOpened(Incident $incident, ?User $actor = null): EventLog
    {
        return EventLog::record(
            $incident,
            'incident_opened',
            'Incident opened: '.$incident->title,
            $actor?->getKey(),
            ['status' => $incident->status, 'external_id' => $incident->external_id, 'title' => $incident->title],
        );
    }

    /**
     * @param  array{starts_at: mixed, ends_at?: mixed, objectives?: string|null}  $data
     */
    public function createOperationalPeriod(Incident $incident, array $data, User $actor): OperationalPeriod
    {
        $period = OperationalPeriod::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'objectives' => $data['objectives'] ?? null,
        ]);

        EventLog::record(
            $incident,
            'operational_period_created',
            'Operational period #'.$period->id.' created',
            $actor->getKey(),
            ['operational_period_id' => $period->id],
        );

        return $period;
    }

    /**
     * @param  array{
     *     operational_period_id?: int|null,
     *     objectives?: string|null,
     *     strategies?: string|null,
     *     safety_message?: string|null
     * }  $data
     */
    public function draftPlan(Incident $incident, array $data, User $actor): IncidentPlan
    {
        $nextVersion = ((int) $incident->plans()->max('version')) + 1;

        $plan = IncidentPlan::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
            'operational_period_id' => $data['operational_period_id'] ?? null,
            'objectives' => $data['objectives'] ?? null,
            'strategies' => $data['strategies'] ?? null,
            'safety_message' => $data['safety_message'] ?? null,
            'version' => max(1, $nextVersion),
            'status' => IncidentPlanStatus::Draft,
        ]);

        EventLog::record(
            $incident,
            'iap_drafted',
            'IAP v'.$plan->version.' drafted',
            $actor->getKey(),
            ['incident_plan_id' => $plan->id, 'version' => $plan->version],
        );

        return $plan;
    }

    /**
     * @param  array{
     *     operational_period_id?: int|null,
     *     objectives?: string|null,
     *     strategies?: string|null,
     *     safety_message?: string|null
     * }  $data
     */
    public function updateDraftPlan(IncidentPlan $plan, array $data, User $actor): IncidentPlan
    {
        abort_unless($plan->status === IncidentPlanStatus::Draft, 422, 'Only draft IAPs can be edited.');

        $plan->fill([
            'operational_period_id' => array_key_exists('operational_period_id', $data)
                ? $data['operational_period_id']
                : $plan->operational_period_id,
            'objectives' => array_key_exists('objectives', $data) ? $data['objectives'] : $plan->objectives,
            'strategies' => array_key_exists('strategies', $data) ? $data['strategies'] : $plan->strategies,
            'safety_message' => array_key_exists('safety_message', $data) ? $data['safety_message'] : $plan->safety_message,
        ]);
        $plan->save();

        EventLog::record(
            $plan->incident,
            'iap_updated',
            'IAP v'.$plan->version.' updated',
            $actor->getKey(),
            ['incident_plan_id' => $plan->id, 'version' => $plan->version],
        );

        return $plan->fresh();
    }

    public function submitPlanForApproval(IncidentPlan $plan, User $actor): IncidentPlan
    {
        abort_unless($plan->status === IncidentPlanStatus::Draft, 422, 'Only draft IAPs can be submitted.');

        $plan->status = IncidentPlanStatus::PendingApproval;
        $plan->save();

        EventLog::record(
            $plan->incident,
            'iap_submitted',
            'IAP v'.$plan->version.' submitted for approval',
            $actor->getKey(),
            ['incident_plan_id' => $plan->id, 'version' => $plan->version],
        );

        return $plan->fresh();
    }

    public function approvePlan(IncidentPlan $plan, User $actor): IncidentPlan
    {
        Gate::forUser($actor)->authorize('disaster.command');

        abort_unless(
            $plan->status === IncidentPlanStatus::PendingApproval || $plan->status === IncidentPlanStatus::Draft,
            422,
            'IAP must be draft or pending approval before approval.',
        );

        $plan->status = IncidentPlanStatus::Approved;
        $plan->approved_by = $actor->getKey();
        $plan->approved_at = now();
        $plan->save();

        EventLog::record(
            $plan->incident,
            'iap_approved',
            'IAP v'.$plan->version.' approved',
            $actor->getKey(),
            ['incident_plan_id' => $plan->id, 'version' => $plan->version],
        );

        return $plan->fresh();
    }

    public function assignRole(Incident $incident, IncidentRoleType $role, User $assignee, User $actor): IncidentRole
    {
        Gate::forUser($actor)->authorize('disaster.command');

        abort_unless(
            (int) $assignee->organization_id === (int) $incident->organization_id,
            422,
            'Assignee must belong to the same organization.',
        );

        $assignment = IncidentRole::query()->updateOrCreate(
            [
                'incident_id' => $incident->id,
                'role' => $role->value,
            ],
            [
                'organization_id' => $incident->organization_id,
                'user_id' => $assignee->getKey(),
            ],
        );

        EventLog::record(
            $incident,
            'role_assigned',
            $role->value.' assigned to user #'.$assignee->getKey(),
            $actor->getKey(),
            ['role' => $role->value, 'user_id' => $assignee->getKey(), 'incident_role_id' => $assignment->id],
        );

        return $assignment;
    }

    public function removeRole(IncidentRole $assignment, User $actor): void
    {
        Gate::forUser($actor)->authorize('disaster.command');

        $incident = $assignment->incident;
        $roleValue = $assignment->role instanceof IncidentRoleType
            ? $assignment->role->value
            : (string) $assignment->role;

        EventLog::record(
            $incident,
            'role_removed',
            $roleValue.' role cleared',
            $actor->getKey(),
            ['role' => $roleValue, 'user_id' => $assignment->user_id, 'incident_role_id' => $assignment->id],
        );

        $assignment->delete();
    }

    /**
     * @param  array{
     *     title: string,
     *     assigned_avl_unit_id?: int|null,
     *     assigned_user_id?: int|null,
     *     status?: string,
     *     priority?: string,
     *     due_at?: mixed,
     *     result?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     location?: array<string, mixed>|null
     * }  $data
     */
    public function createTask(Incident $incident, array $data, User $actor): Task
    {
        Gate::forUser($actor)->authorize('disaster.dispatch');

        $task = Task::query()->create($this->taskAttributes($incident, $data));

        EventLog::record(
            $incident,
            'task_created',
            'Task created: '.$task->title,
            $actor->getKey(),
            ['task_id' => $task->id, 'title' => $task->title],
        );

        return $task->fresh(['assignedAvlUnit', 'assignedUser']);
    }

    /**
     * @param  array{
     *     title?: string,
     *     assigned_avl_unit_id?: int|null,
     *     assigned_user_id?: int|null,
     *     status?: string,
     *     priority?: string,
     *     due_at?: mixed,
     *     result?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     location?: array<string, mixed>|null
     * }  $data
     */
    public function updateTask(Task $task, array $data, User $actor): Task
    {
        Gate::forUser($actor)->authorize('disaster.dispatch');

        $attrs = $this->taskAttributes($task->incident, $data, updating: true);
        $task->fill($attrs);
        if (array_key_exists('geom', $attrs)) {
            $task->geom = $attrs['geom'];
        }
        $task->save();

        EventLog::record(
            $task->incident,
            'task_updated',
            'Task #'.$task->id.' updated',
            $actor->getKey(),
            [
                'task_id' => $task->id,
                'status' => $task->status instanceof TaskStatus ? $task->status->value : $task->status,
                'assigned_avl_unit_id' => $task->assigned_avl_unit_id,
            ],
        );

        return $task->fresh(['assignedAvlUnit', 'assignedUser']);
    }

    /**
     * @param  array{
     *     summary?: string|null,
     *     casualties?: array<string, mixed>|null,
     *     resources?: array<string, mixed>|null,
     *     lifelines_status?: array<string, mixed>|null,
     *     next_steps?: string|null
     * }  $data
     */
    public function createSitRep(Incident $incident, array $data, User $actor): SitRep
    {
        $prefill = $this->sitRepPrefill($incident);
        $payload = [
            'summary' => $data['summary'] ?? $prefill['summary'],
            'casualties' => $data['casualties'] ?? $prefill['casualties'],
            'resources' => $data['resources'] ?? $prefill['resources'],
            'lifelines_status' => $data['lifelines_status'] ?? $prefill['lifelines_status'],
            'next_steps' => $data['next_steps'] ?? $prefill['next_steps'],
        ];

        $exported = SitRepExporter::toEdxlShape($incident, $payload, $actor);

        $sitrep = SitRep::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
            'summary' => $payload['summary'],
            'casualties' => $payload['casualties'],
            'resources' => $payload['resources'],
            'lifelines_status' => $payload['lifelines_status'],
            'next_steps' => $payload['next_steps'],
            'prepared_by' => $actor->getKey(),
            'prepared_at' => now(),
            'exported_json' => $exported,
        ]);

        EventLog::record(
            $incident,
            'sitrep_created',
            'SitRep #'.$sitrep->id.' prepared',
            $actor->getKey(),
            ['sitrep_id' => $sitrep->id],
        );

        return $sitrep;
    }

    public function closeIncident(Incident $incident, User $actor): Incident
    {
        Gate::forUser($actor)->authorize('disaster.dispatch');

        if ($incident->status === 'closed') {
            throw ValidationException::withMessages(['status' => 'Incident is already closed.']);
        }

        $incident->status = 'closed';
        $incident->save();

        EventLog::record(
            $incident,
            'incident_closed',
            'Incident closed',
            $actor->getKey(),
            ['status' => 'closed'],
        );

        return $incident->fresh();
    }

    /**
     * @return array{
     *     summary: string,
     *     casualties: array<string, mixed>,
     *     resources: array<string, mixed>,
     *     lifelines_status: array<string, mixed>,
     *     next_steps: string
     * }
     */
    public function sitRepPrefill(Incident $incident): array
    {
        $organizationId = (int) $incident->organization_id;

        $openTasks = Task::query()
            ->forOrganization($organizationId)
            ->where('incident_id', $incident->id)
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->orderBy('id')
            ->get(['id', 'title', 'status', 'priority', 'assigned_avl_unit_id']);

        $avlCounts = AvlUnit::query()
            ->forOrganization($organizationId)
            ->select('resource_status', DB::raw('count(*) as aggregate'))
            ->groupBy('resource_status')
            ->pluck('aggregate', 'resource_status')
            ->all();

        $resources = [
            'avl_by_status' => $avlCounts,
            'avl_total' => array_sum(array_map('intval', $avlCounts)),
            'medical_facilities' => MedicalFacility::query()->forOrganization($organizationId)->count(),
            'staging_areas' => StagingArea::query()->forOrganization($organizationId)->count(),
            'critical_sites' => CriticalSite::query()->forOrganization($organizationId)->count(),
            'open_tasks' => $openTasks->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status instanceof TaskStatus ? $task->status->value : $task->status,
                'priority' => $task->priority instanceof TaskPriority ? $task->priority->value : $task->priority,
                'assigned_avl_unit_id' => $task->assigned_avl_unit_id,
            ])->all(),
        ];

        $nextSteps = $openTasks->isEmpty()
            ? 'No open tasks. Review IAP objectives and close when demobilized.'
            : 'Complete open tasks: '.$openTasks->pluck('title')->take(5)->implode('; ');

        return [
            'summary' => sprintf(
                'SitRep for incident "%s" (status: %s). Open tasks: %d. AVL units: %d.',
                $incident->title,
                $incident->status,
                $openTasks->count(),
                $resources['avl_total'],
            ),
            'casualties' => [
                'injured' => null,
                'fatalities' => null,
                'missing' => null,
                'notes' => null,
            ],
            'resources' => $resources,
            'lifelines_status' => [
                'safety_security' => 'unknown',
                'food_water_shelter' => 'unknown',
                'health_medical' => 'unknown',
                'energy' => 'unknown',
                'communications' => 'unknown',
                'transportation' => 'unknown',
                'hazardous_materials' => 'unknown',
                'water_systems' => 'unknown',
            ],
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function taskAttributes(Incident $incident, array $data, bool $updating = false): array
    {
        $attrs = [
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
        ];

        if (! $updating || array_key_exists('title', $data)) {
            $attrs['title'] = $data['title'] ?? null;
        }
        if (array_key_exists('assigned_avl_unit_id', $data)) {
            $attrs['assigned_avl_unit_id'] = $data['assigned_avl_unit_id'];
            if ($data['assigned_avl_unit_id'] !== null && (! $updating || ! isset($data['status']))) {
                $attrs['status'] = TaskStatus::Assigned->value;
            }
        }
        if (array_key_exists('assigned_user_id', $data)) {
            $attrs['assigned_user_id'] = $data['assigned_user_id'];
        }
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $attrs['status'] = $data['status'];
        }
        if (array_key_exists('priority', $data) && $data['priority'] !== null) {
            $attrs['priority'] = $data['priority'];
        }
        if (array_key_exists('due_at', $data)) {
            $attrs['due_at'] = $data['due_at'];
        }
        if (array_key_exists('result', $data)) {
            $attrs['result'] = $data['result'];
        }
        if (array_key_exists('location', $data)) {
            $attrs['location'] = $data['location'];
        }

        if (isset($data['latitude'], $data['longitude']) && $data['latitude'] !== null && $data['longitude'] !== null) {
            $attrs['geom'] = GeometryWriter::pointFromCoordinates((float) $data['latitude'], (float) $data['longitude']);
            $attrs['location'] = [
                'type' => 'Point',
                'coordinates' => [(float) $data['longitude'], (float) $data['latitude']],
            ];
        }

        if ($updating) {
            unset($attrs['organization_id'], $attrs['incident_id']);
            if (! array_key_exists('title', $data)) {
                unset($attrs['title']);
            }
        }

        return $attrs;
    }
}
