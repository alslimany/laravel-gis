<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #66 re-verify (4d8e32f) QA coverage. */
class UserOrganizationScopingReverifyTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Role $admin;

    protected Role $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->viewer = Role::create(['name' => 'viewer', 'description' => 'Viewer']);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    protected function userIn(?Organization $org, ?Role $role = null): User
    {
        $u = User::factory()->create(['organization_id' => $org?->id]);
        if ($role) {
            $u->roles()->attach($role);
        }

        return $u;
    }

    public function test_duplicate_role_in_same_org_is_rejected(): void
    {
        $u = $this->userIn($this->orgA, $this->admin);
        $this->expectException(QueryException::class);
        $u->roles()->attach($this->admin, ['organization_id' => $this->orgA->id]);
    }

    public function test_legacy_null_org_grant_does_not_count_for_org_member(): void
    {
        $u = $this->userIn($this->orgA);
        DB::table('role_user')->insert(['role_id' => $this->admin->id, 'user_id' => $u->id, 'organization_id' => null]);
        $this->assertFalse($u->fresh()->hasRole('admin'));
        $this->actingAs($u)->get('/users')->assertForbidden();
    }

    public function test_superadmin_role_is_rejected(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $member = $this->userIn($this->orgA);
        $super = Role::create(['name' => 'superadmin', 'description' => 'x']);
        $this->actingAs($admin)->put("/users/{$member->id}", [
            'name' => $member->name, 'email' => $member->email, 'roles' => [$super->id, 999999],
        ])->assertSessionHasErrors(['roles.0', 'roles.1']);
        $this->assertFalse($member->fresh()->roles()->exists());
    }

    public function test_role_sync_leaves_grants_in_other_orgs_alone(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $member = $this->userIn($this->orgA);
        $member->roles()->attach($this->admin, ['organization_id' => $this->orgB->id]);
        $this->actingAs($admin)->put("/users/{$member->id}", [
            'name' => $member->name, 'email' => $member->email, 'roles' => [$this->viewer->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('role_user', ['user_id' => $member->id, 'role_id' => $this->admin->id, 'organization_id' => $this->orgB->id]);
        $this->assertDatabaseHas('role_user', ['user_id' => $member->id, 'role_id' => $this->viewer->id, 'organization_id' => $this->orgA->id]);
    }

    public function test_admin_can_keep_own_admin_role_while_editing_self(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => 'Renamed', 'email' => $admin->email, 'roles' => [$this->admin->id, $this->viewer->id],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_admin_cannot_delete_self_and_null_org_target_is_404(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $orphan = $this->userIn(null);
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertForbidden();
        $this->actingAs($admin)->get("/users/{$orphan->id}/edit")->assertNotFound();
        $this->actingAs($admin)->delete("/users/{$orphan->id}")->assertNotFound();
    }
}
