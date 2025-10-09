<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectComment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $admin;
    protected User $editor;
    protected User $viewer;
    protected Organization $organization;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        $adminRole = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $viewerRole = Role::create(['name' => 'viewer', 'description' => 'Viewer']);

        // Create organization
        $this->organization = Organization::factory()->create();
        
        // Create users with different roles
        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->roles()->attach($adminRole);

        $this->editor = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->editor->roles()->attach($editorRole);

        $this->viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->viewer->roles()->attach($viewerRole);

        $this->user = $this->editor;

        // Create a test project
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'is_public' => false,
        ]);
    }

    public function test_user_can_view_projects_index()
    {
        $response = $this->actingAs($this->user)->get(route('projects.index'));

        $response->assertStatus(200);
        $response->assertViewIs('projects.index');
        $response->assertViewHas('projects');
    }

    public function test_user_without_organization_cannot_view_projects()
    {
        $userWithoutOrg = User::factory()->create(['organization_id' => null]);

        $response = $this->actingAs($userWithoutOrg)->get(route('projects.index'));

        $response->assertStatus(403);
    }

    public function test_editor_can_create_project()
    {
        $response = $this->actingAs($this->editor)->get(route('projects.create'));

        $response->assertStatus(200);
        $response->assertViewIs('projects.create');
    }

    public function test_viewer_cannot_create_project()
    {
        $response = $this->actingAs($this->viewer)->get(route('projects.create'));

        $response->assertStatus(403);
    }

    public function test_user_can_store_new_project()
    {
        $projectData = [
            'name' => 'Test Project',
            'description' => 'Test Description',
            'is_public' => false,
        ];

        $response = $this->actingAs($this->editor)
            ->post(route('projects.store'), $projectData);

        $response->assertRedirect();
        $this->assertDatabaseHas('projects', [
            'name' => 'Test Project',
            'organization_id' => $this->organization->id,
            'user_id' => $this->editor->id,
        ]);
    }

    public function test_project_creates_activity_log_on_creation()
    {
        $projectData = [
            'name' => 'Test Project with Activity',
            'description' => 'Test Description',
        ];

        $this->actingAs($this->editor)
            ->post(route('projects.store'), $projectData);

        $project = Project::where('name', 'Test Project with Activity')->first();

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'action' => 'created',
        ]);
    }

    public function test_user_can_view_project_from_same_organization()
    {
        $response = $this->actingAs($this->user)->get(route('projects.show', $this->project));

        $response->assertStatus(200);
        $response->assertViewIs('projects.show');
        $response->assertSee($this->project->name);
    }

    public function test_user_from_different_organization_cannot_view_private_project()
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $response = $this->actingAs($otherUser)->get(route('projects.show', $this->project));

        $response->assertStatus(403);
    }

    public function test_anyone_can_view_public_project_with_token()
    {
        $this->project->update(['is_public' => true]);

        $response = $this->get(route('projects.shared', $this->project->share_token));

        $response->assertStatus(200);
        $response->assertSee($this->project->name);
    }

    public function test_cannot_view_shared_project_with_invalid_token()
    {
        $response = $this->get(route('projects.shared', 'invalid-token'));

        $response->assertStatus(404);
    }

    public function test_editor_can_update_project()
    {
        $updateData = [
            'name' => 'Updated Project Name',
            'description' => 'Updated Description',
        ];

        $response = $this->actingAs($this->editor)
            ->put(route('projects.update', $this->project), $updateData);

        $response->assertRedirect();
        $this->assertDatabaseHas('projects', [
            'id' => $this->project->id,
            'name' => 'Updated Project Name',
        ]);
    }

    public function test_viewer_cannot_update_project()
    {
        $updateData = [
            'name' => 'Should Not Update',
            'description' => 'Updated Description',
        ];

        $response = $this->actingAs($this->viewer)
            ->put(route('projects.update', $this->project), $updateData);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_project()
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('projects.destroy', $this->project));

        $response->assertRedirect();
        $this->assertDatabaseMissing('projects', [
            'id' => $this->project->id,
        ]);
    }

    public function test_editor_cannot_delete_project()
    {
        $response = $this->actingAs($this->editor)
            ->delete(route('projects.destroy', $this->project));

        $response->assertStatus(403);
    }

    public function test_user_can_access_share_page()
    {
        $response = $this->actingAs($this->editor)
            ->get(route('projects.share', $this->project));

        $response->assertStatus(200);
        $response->assertViewIs('projects.share');
    }

    public function test_user_can_toggle_project_visibility()
    {
        $this->assertFalse($this->project->is_public);

        $response = $this->actingAs($this->editor)
            ->put(route('projects.update', $this->project), [
                'name' => $this->project->name,
                'is_public' => true,
            ]);

        $this->project->refresh();
        $this->assertTrue($this->project->is_public);
    }

    public function test_user_can_add_collaborator()
    {
        $newUser = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->editor)
            ->post(route('projects.invite.store', $this->project), [
                'user_id' => $newUser->id,
                'role' => 'viewer',
            ]);

        $response->assertRedirect();
        $this->assertTrue($this->project->collaborators->contains($newUser));
    }

    public function test_cannot_add_collaborator_from_different_organization()
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $response = $this->actingAs($this->editor)
            ->post(route('projects.invite.store', $this->project), [
                'user_id' => $otherUser->id,
                'role' => 'viewer',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_remove_collaborator()
    {
        $collaborator = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->project->collaborators()->attach($collaborator->id, ['role' => 'viewer']);

        $response = $this->actingAs($this->editor)
            ->delete(route('projects.collaborators.remove', [$this->project, $collaborator]));

        $response->assertRedirect();
        $this->assertFalse($this->project->fresh()->collaborators->contains($collaborator));
    }

    public function test_user_can_update_collaborator_role()
    {
        $collaborator = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->project->collaborators()->attach($collaborator->id, ['role' => 'viewer']);

        $response = $this->actingAs($this->editor)
            ->put(route('projects.collaborators.role', [$this->project, $collaborator]), [
                'role' => 'editor',
            ]);

        $response->assertRedirect();
        $this->assertEquals('editor', $this->project->fresh()->collaborators->first()->pivot->role);
    }

    public function test_user_can_add_comment_to_project()
    {
        $commentData = [
            'content' => 'This is a test comment',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('projects.comments.store', $this->project), $commentData);

        $response->assertRedirect();
        $this->assertDatabaseHas('project_comments', [
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'content' => 'This is a test comment',
        ]);
    }

    public function test_comment_owner_can_delete_comment()
    {
        $comment = ProjectComment::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'content' => 'Test comment to delete',
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('projects.comments.destroy', [$this->project, $comment->id]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('project_comments', [
            'id' => $comment->id,
        ]);
    }

    public function test_project_owner_can_delete_any_comment()
    {
        $otherUser = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        
        $comment = ProjectComment::create([
            'project_id' => $this->project->id,
            'user_id' => $otherUser->id,
            'content' => 'Comment from another user',
        ]);

        $response = $this->actingAs($this->user) // project owner
            ->delete(route('projects.comments.destroy', [$this->project, $comment->id]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('project_comments', [
            'id' => $comment->id,
        ]);
    }

    public function test_projects_are_filtered_by_organization()
    {
        $otherOrg = Organization::factory()->create();
        
        // Create projects in different organizations
        Project::factory()->count(2)->create([
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('projects.index'));

        $response->assertStatus(200);
        $projects = $response->viewData('projects');
        
        // Should only see project from own organization
        foreach ($projects as $project) {
            $this->assertEquals($this->organization->id, $project->organization_id);
        }
    }

    public function test_collaborator_can_view_project()
    {
        $collaborator = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->project->collaborators()->attach($collaborator->id, ['role' => 'viewer']);

        $response = $this->actingAs($collaborator)
            ->get(route('projects.show', $this->project));

        $response->assertStatus(200);
    }

    public function test_project_has_unique_share_token()
    {
        $project1 = Project::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $project2 = Project::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertNotEquals($project1->share_token, $project2->share_token);
        $this->assertNotNull($project1->share_token);
        $this->assertNotNull($project2->share_token);
    }
}
