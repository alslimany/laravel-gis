<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        Role::create(['name' => 'admin', 'description' => 'Administrator']);
        Role::create(['name' => 'editor', 'description' => 'Editor']);
        Role::create(['name' => 'viewer', 'description' => 'Viewer']);
    }

    public function test_only_admin_can_access_user_management(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $viewer = User::factory()->create();
        $viewer->roles()->attach(Role::where('name', 'viewer')->first());

        // Admin can access
        $response = $this->actingAs($admin)->get('/users');
        $response->assertStatus(200);

        // Viewer cannot access
        $response = $this->actingAs($viewer)->get('/users');
        $response->assertStatus(403);
    }

    public function test_admin_can_edit_users_in_same_organization(): void
    {
        $organization = Organization::factory()->create();

        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($admin)->get("/users/{$user->id}/edit");
        $response->assertStatus(200);
    }

    public function test_admin_cannot_edit_users_in_different_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $admin = User::factory()->create(['organization_id' => $org1->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $user = User::factory()->create(['organization_id' => $org2->id]);

        $response = $this->actingAs($admin)->get("/users/{$user->id}/edit");
        $response->assertStatus(403);
    }

    public function test_editor_can_create_projects(): void
    {
        $organization = Organization::factory()->create();

        $editor = User::factory()->create(['organization_id' => $organization->id]);
        $editor->roles()->attach(Role::where('name', 'editor')->first());

        $this->assertTrue($editor->can('create', Project::class));
    }

    public function test_viewer_cannot_create_projects(): void
    {
        $organization = Organization::factory()->create();

        $viewer = User::factory()->create(['organization_id' => $organization->id]);
        $viewer->roles()->attach(Role::where('name', 'viewer')->first());

        $this->assertFalse($viewer->can('create', Project::class));
    }

    public function test_user_can_view_project_in_same_organization(): void
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_user_cannot_view_project_in_different_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org1->id]);
        $project = Project::factory()->create(['organization_id' => $org2->id]);

        $this->assertFalse($user->can('view', $project));
    }

    public function test_admin_can_delete_projects(): void
    {
        $organization = Organization::factory()->create();

        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($admin->can('delete', $project));
    }

    public function test_editor_cannot_delete_projects(): void
    {
        $organization = Organization::factory()->create();

        $editor = User::factory()->create(['organization_id' => $organization->id]);
        $editor->roles()->attach(Role::where('name', 'editor')->first());

        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($editor->can('delete', $project));
    }

    public function test_user_without_organization_cannot_access_organization_settings(): void
    {
        $user = User::factory()->create(['organization_id' => null]);

        $response = $this->actingAs($user)->get('/organization/settings');
        $response->assertStatus(403);
    }

    public function test_admin_can_update_organization_settings(): void
    {
        $organization = Organization::factory()->create();

        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->assertTrue($admin->can('update', $organization));
    }

    public function test_viewer_cannot_update_organization_settings(): void
    {
        $organization = Organization::factory()->create();

        $viewer = User::factory()->create(['organization_id' => $organization->id]);
        $viewer->roles()->attach(Role::where('name', 'viewer')->first());

        $this->assertFalse($viewer->can('update', $organization));
    }
}
