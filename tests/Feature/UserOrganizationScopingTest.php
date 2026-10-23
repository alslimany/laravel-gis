<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SolutionPackDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * #66: admins are organization admins, never global; organization_id is not
 * mass assignable; role grants are scoped to an organization (D2).
 */
class UserOrganizationScopingTest extends TestCase
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
        $user = User::factory()->create(['organization_id' => $org?->id]);
        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_admin_cannot_view_edit_update_or_delete_user_in_other_org(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $foreign = $this->userIn($this->orgB);

        $this->actingAs($admin)->get("/users/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($admin)->put("/users/{$foreign->id}", [
            'name' => 'Hijacked', 'email' => 'x@example.com',
        ])->assertNotFound();
        $this->actingAs($admin)->delete("/users/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $foreign->id, 'name' => $foreign->name]);
    }

    public function test_admin_can_manage_user_in_same_org(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $member = $this->userIn($this->orgA);

        $this->actingAs($admin)->get("/users/{$member->id}/edit")->assertOk();
        $this->actingAs($admin)->put("/users/{$member->id}", [
            'name' => 'Renamed', 'email' => $member->email, 'roles' => [$this->viewer->id],
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Renamed']);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $member->id, 'role_id' => $this->viewer->id, 'organization_id' => $this->orgA->id,
        ]);
    }

    public function test_index_lists_only_own_org_users(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $this->userIn($this->orgA);
        $this->userIn($this->orgB);
        $this->userIn(null);

        $this->actingAs($admin)->get('/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.total', 2));
    }

    public function test_null_org_admin_is_denied_user_management(): void
    {
        $admin = $this->userIn(null, $this->admin);
        $otherNullOrg = $this->userIn(null);

        $this->actingAs($admin)->get('/users')->assertForbidden();
        $this->actingAs($admin)->get("/users/{$otherNullOrg->id}/edit")->assertForbidden();
        $this->assertFalse($admin->can('update', $otherNullOrg));
    }

    public function test_admin_cannot_manage_null_org_user(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $orphan = $this->userIn(null);

        $this->actingAs($admin)->get("/users/{$orphan->id}/edit")->assertNotFound();
    }

    public function test_update_cannot_change_organization_id(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $member = $this->userIn($this->orgA);

        $this->actingAs($admin)->put("/users/{$member->id}", [
            'name' => $member->name, 'email' => $member->email, 'organization_id' => $this->orgB->id,
        ]);

        $this->assertSame($this->orgA->id, (int) $member->fresh()->organization_id);
    }

    public function test_organization_id_is_not_mass_assignable(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'organization_id' => $this->orgB->id]);
        $this->assertNull($user->organization_id);

        $member = $this->userIn($this->orgA);
        $member->update(['organization_id' => $this->orgB->id]);
        $this->assertSame($this->orgA->id, (int) $member->fresh()->organization_id);
    }

    public function test_role_grant_is_scoped_to_org_it_was_granted_in(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertDatabaseHas('role_user', ['user_id' => $admin->id, 'organization_id' => $this->orgA->id]);

        // Moving the user to another org does not carry the admin grant along.
        $admin->forceFill(['organization_id' => $this->orgB->id])->save();
        $this->assertFalse($admin->fresh()->hasRole('admin'));
        $this->actingAs($admin->fresh())->get('/users')->assertForbidden();
    }

    public function test_null_org_user_only_gets_null_org_grants(): void
    {
        $user = $this->userIn(null, $this->viewer);
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'organization_id' => null]);
        // Follow-up: a legacy null-org grant confers no role at all.
        $this->assertFalse($user->hasRole('viewer'));

        // A grant tied to an org does not apply to an org-less user.
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgA->id]);
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_migration_backfills_role_user_organization_from_users(): void
    {
        $migration = require database_path('migrations/2026_10_23_000000_add_organization_id_to_role_user_table.php');
        $withOrg = $this->userIn($this->orgA);
        $withoutOrg = $this->userIn(null);

        $migration->down();
        DB::table('role_user')->insert([
            ['role_id' => $this->admin->id, 'user_id' => $withOrg->id],
            ['role_id' => $this->viewer->id, 'user_id' => $withoutOrg->id],
        ]);
        $migration->up();

        $this->assertSame($this->orgA->id, (int) DB::table('role_user')->where('user_id', $withOrg->id)->value('organization_id'));
        $this->assertNull(DB::table('role_user')->where('user_id', $withoutOrg->id)->value('organization_id'));
        $this->assertTrue($withOrg->fresh()->hasRole('admin'));
    }

    public function test_solution_pack_admin_routes_are_org_scoped(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $viewerA = $this->userIn($this->orgA, $this->viewer);
        $nullAdmin = $this->userIn(null, $this->admin);
        // Admin grant made in org B does not make an org A member an admin.
        $crossGrant = $this->userIn($this->orgA);
        $crossGrant->roles()->attach($this->admin, ['organization_id' => $this->orgB->id]);

        $this->actingAs($adminA)->get('/solution-packs')->assertOk();
        $this->actingAs($viewerA)->get('/solution-packs')->assertForbidden();
        $this->actingAs($crossGrant)->get('/solution-packs')->assertForbidden();
        $this->actingAs($crossGrant)->get('/users')->assertForbidden();
        $this->actingAs($nullAdmin)->get('/solution-packs')->assertForbidden();
    }

    public function test_no_org_user_with_legacy_null_admin_grant_is_not_admin_anywhere(): void
    {
        $legacy = $this->userIn(null, $this->admin);
        $this->assertDatabaseHas('role_user', ['user_id' => $legacy->id, 'organization_id' => null]);

        $this->assertFalse($legacy->hasRole('admin'));
        $this->assertFalse($legacy->hasRoleInOrg('admin', null));
        $this->assertFalse($legacy->can('create', Organization::class));
        $this->assertFalse($legacy->can('create', Group::class));
        $this->actingAs($legacy)->get('/organization/settings')->assertForbidden();
        $this->actingAs($legacy)->get('/users')->assertForbidden();
    }

    public function test_same_role_can_be_granted_in_two_orgs(): void
    {
        $user = $this->userIn($this->orgA, $this->admin);
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgB->id]);

        $this->assertSame(2, DB::table('role_user')->where('user_id', $user->id)->where('role_id', $this->admin->id)->count());
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $this->userIn($this->orgA, $this->admin);

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'roles' => [$this->viewer->id],
        ])->assertSessionHasErrors('roles');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_can_demote_other_admin_when_another_admin_remains(): void
    {
        $actor = $this->userIn($this->orgA, $this->admin);
        $target = $this->userIn($this->orgA, $this->admin);

        // Two admins: demoting the other one is fine.
        $this->actingAs($actor)->put("/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'roles' => [$this->viewer->id],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->hasRole('admin'));

        // An admin grant in another org does not count toward org A.
        $actor2 = $this->userIn($this->orgB, $this->admin);
        $actor2->forceFill(['organization_id' => $this->orgA->id])->save();
        $this->assertFalse($actor2->fresh()->hasRole('admin'));
    }

    public function test_last_admin_guard_blocks_demoting_sole_org_admin(): void
    {
        // Over HTTP the acting admin always counts as another admin, so the
        // last-admin rule is a defensive backstop; exercise it directly.
        $target = $this->userIn($this->orgA, $this->admin);
        $actor = $this->userIn($this->orgA);

        $controller = new UserController;
        $guard = new \ReflectionMethod($controller, 'guardAdminRemoval');

        try {
            $guard->invoke($controller, $actor, $target, [$this->viewer->id]);
            $this->fail('Expected last-admin guard to throw.');
        } catch (ValidationException $e) {
            $this->assertSame('An organization must keep at least one admin.', $e->errors()['roles'][0]);
        }
    }

    public function test_roles_outside_allowlist_are_rejected(): void
    {
        $admin = $this->userIn($this->orgA, $this->admin);
        $member = $this->userIn($this->orgA);
        $owner = Role::create(['name' => 'owner', 'description' => 'Not assignable']);

        $this->actingAs($admin)->put("/users/{$member->id}", [
            'name' => $member->name, 'email' => $member->email, 'roles' => [$owner->id],
        ])->assertSessionHasErrors('roles.0');
        $this->assertDatabaseMissing('role_user', ['user_id' => $member->id, 'role_id' => $owner->id]);
    }

    public function test_editor_and_viewer_are_forbidden_on_admin_routes(): void
    {
        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $editor = $this->userIn($this->orgA, $editorRole);
        $viewer = $this->userIn($this->orgA, $this->viewer);
        $member = $this->userIn($this->orgA);

        foreach ([$editor, $viewer] as $user) {
            $this->actingAs($user)->get('/users')->assertForbidden();
            $this->actingAs($user)->get("/users/{$member->id}/edit")->assertForbidden();
            $this->actingAs($user)->put("/users/{$member->id}", ['name' => 'x', 'email' => 'x@example.com'])->assertForbidden();
            $this->actingAs($user)->delete("/users/{$member->id}")->assertForbidden();
            $this->actingAs($user)->get('/solution-packs')->assertForbidden();
            $this->actingAs($user)->get('/solution-packs/create')->assertForbidden();
        }
    }

    public function test_invalid_update_to_other_org_pack_is_404_not_validation_redirect(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $packB = SolutionPackDefinition::create([
            'organization_id' => $this->orgB->id, 'user_id' => $this->userIn($this->orgB)->id, 'slug' => 'b-pack', 'name' => 'Pack B', 'definition' => [],
        ]);

        $this->actingAs($adminA)->put("/solution-packs/{$packB->id}", ['slug' => '', 'name' => ''])
            ->assertNotFound()
            ->assertSessionHasNoErrors();
    }
}
