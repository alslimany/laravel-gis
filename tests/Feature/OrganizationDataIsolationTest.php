<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationDataIsolationTest extends TestCase
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

    public function test_users_can_only_see_projects_in_their_organization(): void
    {
        $org1 = Organization::factory()->create(['name' => 'Org 1']);
        $org2 = Organization::factory()->create(['name' => 'Org 2']);

        $user1 = User::factory()->create(['organization_id' => $org1->id]);
        $user2 = User::factory()->create(['organization_id' => $org2->id]);

        $project1 = Project::factory()->create(['organization_id' => $org1->id, 'name' => 'Project 1']);
        $project2 = Project::factory()->create(['organization_id' => $org2->id, 'name' => 'Project 2']);

        // User 1 can see Project 1 but not Project 2
        $this->assertTrue($user1->can('view', $project1));
        $this->assertFalse($user1->can('view', $project2));

        // User 2 can see Project 2 but not Project 1
        $this->assertTrue($user2->can('view', $project2));
        $this->assertFalse($user2->can('view', $project1));
    }

    public function test_organization_scope_filters_projects_correctly(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        Project::factory()->count(3)->create(['organization_id' => $org1->id]);
        Project::factory()->count(2)->create(['organization_id' => $org2->id]);

        $org1Projects = Project::forOrganization($org1->id)->get();
        $org2Projects = Project::forOrganization($org2->id)->get();

        $this->assertCount(3, $org1Projects);
        $this->assertCount(2, $org2Projects);
    }

    public function test_organization_members_count_is_correct(): void
    {
        $organization = Organization::factory()->create();

        User::factory()->count(5)->create(['organization_id' => $organization->id]);
        User::factory()->count(3)->create(['organization_id' => null]);

        $this->assertEquals(5, $organization->members()->count());
    }

    public function test_dashboard_shows_only_organization_projects(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $user1 = User::factory()->create(['organization_id' => $org1->id]);

        Project::factory()->count(3)->create(['organization_id' => $org1->id]);
        Project::factory()->count(2)->create(['organization_id' => $org2->id]);

        $response = $this->actingAs($user1)->get('/dashboard');

        $response->assertStatus(200);
        // Dashboard should only show projects from org1
        $response->assertInertia(fn ($page) => $page->component('Dashboard')->has('projects.data', 3));
    }

    public function test_admin_can_only_manage_users_in_same_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $admin1 = User::factory()->create(['organization_id' => $org1->id]);
        $admin1->roles()->attach(Role::where('name', 'admin')->first());

        User::factory()->count(3)->create(['organization_id' => $org1->id]);
        User::factory()->count(2)->create(['organization_id' => $org2->id]);

        $response = $this->actingAs($admin1)->get('/users');

        $response->assertStatus(200);
        // Should only see users from org1 (3 users + admin = 4)
        $response->assertInertia(fn ($page) => $page->component('Users/Index')->where('users.total', 4));
    }

    public function test_user_without_organization_cannot_create_projects(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->roles()->attach(Role::where('name', 'editor')->first());

        $this->assertFalse($user->can('create', Project::class));
    }

    public function test_organization_settings_page_shows_correct_organization(): void
    {
        $organization = Organization::factory()->create(['name' => 'Test Org']);

        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $response = $this->actingAs($admin)->get('/organization/settings');

        $response->assertStatus(200);
        $response->assertSee('Test Org');
        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Settings')
            ->where('organization.id', $organization->id));
    }

    public function test_user_can_belong_to_only_one_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org1->id]);

        // Verify user belongs to org1
        $this->assertEquals($org1->id, $user->organization_id);
        $this->assertEquals($org1->id, $user->organization->id);

        // Change to org2 (organization_id is not mass assignable, #66)
        $user->forceFill(['organization_id' => $org2->id])->save();
        $user->refresh();

        // Verify user now belongs to org2 only
        $this->assertEquals($org2->id, $user->organization_id);
        $this->assertEquals($org2->id, $user->organization->id);
    }

    public function test_console_modules_keep_organization_pages_out_of_core_gis(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $modules = collect($response->viewData('page')['props']['modules']);
        $labels = fn (string $key) => collect($modules->firstWhere('key', $key)['sections'])
            ->flatMap(fn (array $section) => collect($section['items'])->pluck('label'))
            ->all();

        $this->assertNotContains('Organization', $labels('core'));
        $this->assertContains('Settings', $labels('admin'));
        // Solution packs is admin-only; a non-admin never sees it.
        $this->assertNotContains('Solution packs', $labels('admin'));
    }

    public function test_admin_console_modules_include_solution_packs(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $adminModule = collect($response->viewData('page')['props']['modules'])->firstWhere('key', 'admin');
        $packs = collect($adminModule['sections'])->flatMap(fn (array $section) => $section['items'])->firstWhere('label', 'Solution packs');

        $this->assertSame('packs', $packs['icon']);
        $this->assertSame(route('solution-packs.index'), $packs['href']);
    }
}
