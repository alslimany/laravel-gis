<?php

namespace Lumina\Disaster\Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\EventLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentPlan;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class IcsLiteTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_disaster_command_gate_is_org_admin_only(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->assertTrue(Gate::has('disaster.command'));
        $this->assertTrue(Gate::forUser($this->disasterAdmin)->allows('disaster.command'));
        $this->assertFalse(Gate::forUser($member)->allows('disaster.command'));
    }

    public function test_member_cannot_assign_roles_or_approve_iap(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $incident = $this->openIncident();

        $this->actingAs($member)
            ->postJson("/x/disaster/incidents/{$incident->id}/roles", [
                'role' => IncidentRoleType::Operations->value,
                'user_id' => $member->id,
            ])
            ->assertForbidden();

        $plan = IncidentPlan::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'objectives' => 'Contain fire',
            'version' => 1,
            'status' => IncidentPlanStatus::PendingApproval,
        ]);

        $this->actingAs($member)
            ->postJson("/x/disaster/incidents/{$incident->id}/plans/{$plan->id}/approve")
            ->assertForbidden();
    }

    public function test_lifecycle_open_period_iap_approve_task_sitrep_close(): void
    {
        $incident = $this->openIncident();

        $this->assertSame(1, EventLog::query()->where('incident_id', $incident->id)->where('event_type', 'incident_opened')->count());

        $this->actingAs($this->disasterAdmin)
            ->get("/x/disaster/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/IncidentShow')
                ->where('incident.id', $incident->id)
                ->where('can_command', true)
            );

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/operational-periods", [
                'starts_at' => now()->toIso8601String(),
                'objectives' => 'Stabilize scene',
            ])
            ->assertCreated();

        $periodId = $incident->operationalPeriods()->value('id');
        $this->assertNotNull($periodId);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/plans", [
                'operational_period_id' => $periodId,
                'objectives' => 'Protect life',
                'strategies' => 'Evac north',
                'safety_message' => 'PPE required',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $plan = IncidentPlan::query()->where('incident_id', $incident->id)->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/plans/{$plan->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $plan->refresh();
        $this->assertSame(IncidentPlanStatus::Approved, $plan->status);
        $this->assertSame((int) $this->disasterAdmin->id, (int) $plan->approved_by);
        $this->assertNotNull($plan->approved_at);

        $unit = AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'ENG-1',
            'resource_class' => 'engine',
            'resource_status' => ResourceStatus::Available->value,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/tasks", [
                'title' => 'Stage at north gate',
                'assigned_avl_unit_id' => $unit->id,
                'priority' => 'high',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.avl_unit.resource_status', ResourceStatus::Available->value);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/roles", [
                'role' => IncidentRoleType::IncidentCommander->value,
                'user_id' => $this->disasterAdmin->id,
            ])
            ->assertCreated();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/sitreps", [
                'summary' => 'Scene stable; staging complete.',
                'next_steps' => 'Hold perimeter overnight.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.exported_json.EDXLSitRep.incidentID', $incident->external_id);

        $sitrep = SitRep::query()->where('incident_id', $incident->id)->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->get("/x/disaster/incidents/{$incident->id}/sitreps/{$sitrep->id}/export.json")
            ->assertOk();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $types = EventLog::query()
            ->where('incident_id', $incident->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->all();

        $this->assertContains('incident_opened', $types);
        $this->assertContains('operational_period_created', $types);
        $this->assertContains('iap_drafted', $types);
        $this->assertContains('iap_submitted', $types);
        $this->assertContains('iap_approved', $types);
        $this->assertContains('task_created', $types);
        $this->assertContains('role_assigned', $types);
        $this->assertContains('sitrep_created', $types);
        $this->assertContains('incident_closed', $types);
    }

    public function test_event_log_is_append_only(): void
    {
        $incident = $this->openIncident();
        $log = EventLog::query()->where('incident_id', $incident->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $log->update(['body' => 'tampered']);
    }

    public function test_event_log_cannot_be_deleted(): void
    {
        $incident = $this->openIncident();
        $log = EventLog::query()->where('incident_id', $incident->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_task_assign_requires_dispatch_and_updates_unit_link(): void
    {
        $incident = $this->openIncident();
        $unit = AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'AMB-2',
            'resource_class' => 'ambulance',
            'resource_status' => ResourceStatus::Dispatched->value,
        ]);

        $task = Task::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'title' => 'Medevac standby',
            'status' => TaskStatus::Open,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->putJson("/x/disaster/incidents/{$incident->id}/tasks/{$task->id}", [
                'assigned_avl_unit_id' => $unit->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_avl_unit_id', $unit->id)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.avl_unit.resource_status', ResourceStatus::Dispatched->value);

        $this->assertSame(1, EventLog::query()->where('event_type', 'task_updated')->count());
    }

    public function test_sitrep_prefill_includes_open_tasks_and_avl_counts(): void
    {
        $incident = $this->openIncident();
        AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'U-1',
            'resource_class' => 'engine',
            'resource_status' => ResourceStatus::Available->value,
        ]);
        Task::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'title' => 'Open recon',
            'status' => TaskStatus::Open,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->getJson("/x/disaster/incidents/{$incident->id}/sitreps/prefill")
            ->assertOk()
            ->assertJsonPath('data.resources.avl_total', 1)
            ->assertJsonPath('data.resources.open_tasks.0.title', 'Open recon');
    }

    public function test_cross_tenant_incident_is_hidden(): void
    {
        $otherOrg = Organization::factory()->create();
        $other = User::factory()->create(['organization_id' => $otherOrg->id]);
        $incident = $this->openIncident();

        $this->actingAs($other)
            ->getJson("/x/disaster/incidents/{$incident->id}")
            ->assertNotFound();
    }

    protected function openIncident(): Incident
    {
        $response = $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/incidents', [
                'external_id' => 'INC-ICS-1',
                'title' => 'Warehouse fire',
                'status' => 'open',
                'priority' => 2,
                'latitude' => 32.88,
                'longitude' => 13.19,
            ])
            ->assertCreated();

        return Incident::query()->findOrFail($response->json('data.id'));
    }
}
