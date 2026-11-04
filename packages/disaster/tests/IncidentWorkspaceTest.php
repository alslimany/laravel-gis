<?php

namespace Lumina\Disaster\Tests;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Models\WorkspaceAccessLog;
use Lumina\Disaster\Models\WorkspaceMember;
use Lumina\Disaster\Services\IncidentWorkspaceAccessService;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Support\Enums\WorkspaceMemberRole;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class IncidentWorkspaceTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected Organization $inviteeOrg;

    protected User $inviteeAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();

        $this->inviteeOrg = Organization::factory()->create(['name' => 'Red Crescent']);
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['description' => 'Administrator'],
        );
        $this->inviteeAdmin = User::factory()->create(['organization_id' => $this->inviteeOrg->id]);
        $this->inviteeAdmin->roles()->attach($adminRole->id, ['organization_id' => $this->inviteeOrg->id]);

        (new OrganizationPlugin)->forceFill([
            'organization_id' => $this->inviteeOrg->id,
            'plugin_key' => 'disaster',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
    }

    public function test_owner_can_invite_organization_with_layer_scope(): void
    {
        $incident = $this->openIncident();
        $layer = $this->ownerLayer('Flood extent');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/workspace/invite", [
                'organization_id' => $this->inviteeOrg->id,
                'layer_ids' => [$layer->id],
                'role' => WorkspaceMemberRole::Collaborator->value,
                'expires_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $this->inviteeOrg->id)
            ->assertJsonPath('data.layer_ids.0', $layer->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('dm_workspace_members', [
            'organization_id' => $this->inviteeOrg->id,
            'role' => 'collaborator',
        ]);
        $this->assertSame(1, WorkspaceAccessLog::query()->where('action', 'member_invited')->count());
    }

    public function test_invitee_sees_shared_incident_and_assigned_tasks_only(): void
    {
        $incident = $this->openIncident();
        $layer = $this->ownerLayer('Shared hazard');
        $this->invite($incident, [$layer->id]);

        $assigned = Task::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'title' => 'Assist triage',
            'assigned_user_id' => $this->inviteeAdmin->id,
            'status' => TaskStatus::Assigned,
        ]);
        Task::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'title' => 'Internal only',
            'assigned_user_id' => $this->disasterAdmin->id,
            'status' => TaskStatus::Open,
        ]);

        $this->actingAs($this->inviteeAdmin)
            ->get('/x/disaster/incidents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Incidents')
                ->has('shared_incidents', 1)
                ->where('shared_incidents.0.id', $incident->id)
            );

        $this->actingAs($this->inviteeAdmin)
            ->getJson("/x/disaster/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('data.shared', true)
            ->assertJsonPath('data.shared_layer_ids.0', $layer->id)
            ->assertJsonPath('data.tasks.0.id', $assigned->id)
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.event_log', [])
            ->assertJsonPath('is_owner', false);

        $this->assertSame(1, WorkspaceAccessLog::query()->where('action', 'incident_view')->count());
    }

    public function test_uninvited_org_cannot_view_incident(): void
    {
        $incident = $this->openIncident();
        $strangerOrg = Organization::factory()->create();
        $stranger = User::factory()->create(['organization_id' => $strangerOrg->id]);

        $this->actingAs($stranger)
            ->getJson("/x/disaster/incidents/{$incident->id}")
            ->assertNotFound();
    }

    public function test_invitee_cannot_see_owner_org_other_incidents(): void
    {
        $shared = $this->openIncident();
        $this->invite($shared, []);

        $private = Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'INC-PRIVATE',
            'title' => 'Private fire',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->inviteeAdmin)
            ->getJson("/x/disaster/incidents/{$private->id}")
            ->assertNotFound();

        $this->actingAs($this->inviteeAdmin)
            ->getJson('/x/disaster/incidents')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(1, 'shared')
            ->assertJsonPath('shared.0.id', $shared->id);
    }

    public function test_revocation_removes_access_immediately(): void
    {
        $incident = $this->openIncident();
        $member = $this->invite($incident, []);

        $this->actingAs($this->inviteeAdmin)
            ->getJson("/x/disaster/incidents/{$incident->id}")
            ->assertOk();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/workspace/members/{$member->id}/revoke")
            ->assertOk();

        $this->assertNotNull($member->fresh()->revoked_at);

        $this->actingAs($this->inviteeAdmin)
            ->getJson("/x/disaster/incidents/{$incident->id}")
            ->assertNotFound();
    }

    public function test_expiry_removes_access_immediately_and_command_marks_revoked(): void
    {
        $incident = $this->openIncident();
        $member = $this->invite($incident, [], now()->addHour());

        $this->travel(2)->hours();

        $this->actingAs($this->inviteeAdmin)
            ->getJson("/x/disaster/incidents/{$incident->id}")
            ->assertNotFound();

        Artisan::call('disaster:expire-workspace-members');
        $this->assertNotNull($member->fresh()->revoked_at);
        $this->assertSame(1, WorkspaceAccessLog::query()->where('action', 'member_expired')->count());
    }

    public function test_invitee_collaborator_can_update_assigned_task_only(): void
    {
        $incident = $this->openIncident();
        $this->invite($incident, []);

        $assigned = Task::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'title' => 'Assigned to invitee',
            'assigned_user_id' => $this->inviteeAdmin->id,
            'status' => TaskStatus::Assigned,
        ]);
        $other = Task::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'incident_id' => $incident->id,
            'title' => 'Not theirs',
            'assigned_user_id' => $this->disasterAdmin->id,
            'status' => TaskStatus::Open,
        ]);

        $this->actingAs($this->inviteeAdmin)
            ->putJson("/x/disaster/incidents/{$incident->id}/tasks/{$assigned->id}", [
                'status' => TaskStatus::InProgress->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($this->inviteeAdmin)
            ->putJson("/x/disaster/incidents/{$incident->id}/tasks/{$other->id}", [
                'status' => TaskStatus::Completed->value,
            ])
            ->assertNotFound();
    }

    public function test_cannot_share_other_org_layer_ids(): void
    {
        $incident = $this->openIncident();
        $foreignLayer = Layer::factory()->create([
            'organization_id' => $this->inviteeOrg->id,
            'user_id' => $this->inviteeAdmin->id,
            'name' => 'Foreign layer',
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/workspace/invite", [
                'organization_id' => $this->inviteeOrg->id,
                'layer_ids' => [$foreignLayer->id],
                'expires_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['layer_ids']);
    }

    public function test_layer_visibility_uses_workspace_policy_not_core_content_access(): void
    {
        $incident = $this->openIncident();
        $layer = $this->ownerLayer('Policy layer');
        $this->invite($incident, [$layer->id]);

        $access = app(IncidentWorkspaceAccessService::class);

        $this->assertTrue($access->canViewSharedLayer($this->inviteeAdmin, $incident, $layer->id));
        $this->assertFalse($access->canViewSharedLayer($this->inviteeAdmin, $incident, $layer->id + 999));

        $stranger = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $this->assertFalse($access->canViewSharedLayer($stranger, $incident, $layer->id));
    }

    public function test_access_log_is_append_only(): void
    {
        $incident = $this->openIncident();
        $this->invite($incident, []);
        $log = WorkspaceAccessLog::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_member_cannot_invite(): void
    {
        $incident = $this->openIncident();
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->actingAs($member)
            ->postJson("/x/disaster/incidents/{$incident->id}/workspace/invite", [
                'organization_id' => $this->inviteeOrg->id,
                'expires_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertForbidden();
    }

    protected function openIncident(): Incident
    {
        $response = $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/incidents', [
                'external_id' => 'INC-WS-'.uniqid(),
                'title' => 'Multi-agency flood',
                'status' => 'open',
                'priority' => 2,
                'latitude' => 32.88,
                'longitude' => 13.19,
            ])
            ->assertCreated();

        return Incident::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @param  list<int>  $layerIds
     */
    protected function invite(Incident $incident, array $layerIds, $expiresAt = null): WorkspaceMember
    {
        $response = $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/incidents/{$incident->id}/workspace/invite", [
                'organization_id' => $this->inviteeOrg->id,
                'layer_ids' => $layerIds,
                'role' => WorkspaceMemberRole::Collaborator->value,
                'expires_at' => ($expiresAt ?? now()->addDay())->toIso8601String(),
            ])
            ->assertCreated();

        return WorkspaceMember::query()->findOrFail($response->json('data.id'));
    }

    protected function ownerLayer(string $name): Layer
    {
        return Layer::factory()->create([
            'organization_id' => $this->disasterOrg->id,
            'user_id' => $this->disasterAdmin->id,
            'name' => $name,
        ]);
    }
}
