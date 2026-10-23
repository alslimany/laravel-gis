<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * #66 follow-ups: lossless-as-possible rollback of the widened role_user
 * unique, and the last-admin guard running in a locked transaction.
 */
class UserOrganizationFollowupsTest extends TestCase
{
    use RefreshDatabase;

    protected Role $admin;

    protected Role $viewer;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->viewer = Role::create(['name' => 'viewer', 'description' => 'Viewer']);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    protected function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    public function test_widen_down_dedupes_cross_org_grants_keeping_current_org(): void
    {
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgB->id]);
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgA->id]);
        $other = User::factory()->create(['organization_id' => $this->orgB->id]);
        $other->roles()->attach($this->viewer);

        $this->migration('2026_10_23_000001_widen_role_user_unique_to_organization')->down();

        $rows = DB::table('role_user')->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->orgA->id, (int) $rows->first()->organization_id);
        $this->assertSame(1, DB::table('role_user')->where('user_id', $other->id)->count());
        $this->assertFalse(Schema::hasIndex('role_user', 'role_user_role_user_org_unique'));

        // Old unique is back, and up() re-widens cleanly.
        $this->migration('2026_10_23_000001_widen_role_user_unique_to_organization')->up();
        $this->assertTrue(Schema::hasIndex('role_user', 'role_user_role_user_org_unique'));
    }

    public function test_widen_down_prefers_null_org_grant_over_other_org_grant(): void
    {
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        DB::table('role_user')->insert(['role_id' => $this->viewer->id, 'user_id' => $user->id, 'organization_id' => $this->orgB->id]);
        $null = DB::table('role_user')->insertGetId(['role_id' => $this->viewer->id, 'user_id' => $user->id, 'organization_id' => null]);

        $this->migration('2026_10_23_000001_widen_role_user_unique_to_organization')->down();

        $this->assertSame([$null], DB::table('role_user')->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_widen_down_prefers_current_org_grant_over_null_org_grant(): void
    {
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        DB::table('role_user')->insert(['role_id' => $this->viewer->id, 'user_id' => $user->id, 'organization_id' => null]);
        $current = DB::table('role_user')->insertGetId(['role_id' => $this->viewer->id, 'user_id' => $user->id, 'organization_id' => $this->orgA->id]);

        $this->migration('2026_10_23_000001_widen_role_user_unique_to_organization')->down();

        $this->assertSame([$current], DB::table('role_user')->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_rollback_and_remigrate_does_not_rehome_other_org_admin_grant(): void
    {
        // QA repro: U is in org A but holds admin only in org B.
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgB->id]);
        $this->assertFalse($user->fresh()->hasRoleInOrg('admin', $this->orgA->id));

        $this->rollBackAndRemigrateRoleOrgMigrations();

        $this->assertSame(0, DB::table('role_user')->where('user_id', $user->id)->count());
        $this->assertFalse($user->fresh()->hasRoleInOrg('admin', $this->orgA->id));
    }

    public function test_rollback_and_remigrate_does_not_activate_null_org_grant_of_user_with_org(): void
    {
        // An inert null-org admin grant held by a user who now has an org.
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        DB::table('role_user')->insert(['role_id' => $this->admin->id, 'user_id' => $user->id, 'organization_id' => null]);
        // A user without an org keeps their null-org grant.
        $orgless = User::factory()->create(['organization_id' => null]);
        DB::table('role_user')->insert(['role_id' => $this->viewer->id, 'user_id' => $orgless->id, 'organization_id' => null]);
        $this->assertFalse($user->fresh()->hasRoleInOrg('admin', $this->orgA->id));

        $this->rollBackAndRemigrateRoleOrgMigrations();

        $this->assertSame(0, DB::table('role_user')->where('user_id', $user->id)->count());
        $this->assertFalse($user->fresh()->hasRoleInOrg('admin', $this->orgA->id));
        $kept = DB::table('role_user')->where('user_id', $orgless->id)->get();
        $this->assertCount(1, $kept);
        $this->assertNull($kept->first()->organization_id);
    }

    public function test_rollback_and_remigrate_with_other_org_admin_and_null_org_grant(): void
    {
        // QA pairing: U is in org A, admin in org B, plus a legacy null-org grant.
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        DB::table('role_user')->insert(['role_id' => $this->admin->id, 'user_id' => $user->id, 'organization_id' => $this->orgB->id]);
        DB::table('role_user')->insert(['role_id' => $this->admin->id, 'user_id' => $user->id, 'organization_id' => null]);
        $this->assertFalse($user->fresh()->hasRoleInOrg('admin', $this->orgA->id));

        $this->rollBackAndRemigrateRoleOrgMigrations();

        $this->assertFalse($user->fresh()->hasRoleInOrg('admin', $this->orgA->id));
        $this->assertSame(0, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    /**
     * Run both #66 migrations down then up directly, so the test does not
     * depend on them being the latest two migrations.
     */
    protected function rollBackAndRemigrateRoleOrgMigrations(): void
    {
        $this->migration('2026_10_23_000001_widen_role_user_unique_to_organization')->down();
        $this->migration('2026_10_23_000000_add_organization_id_to_role_user_table')->down();
        $this->assertFalse(Schema::hasColumn('role_user', 'organization_id'));
        $this->migration('2026_10_23_000000_add_organization_id_to_role_user_table')->up();
        $this->migration('2026_10_23_000001_widen_role_user_unique_to_organization')->up();
    }

    public function test_full_rollback_of_both_migrations_with_cross_org_grants(): void
    {
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgA->id]);
        $user->roles()->attach($this->admin, ['organization_id' => $this->orgB->id]);

        // Roll back the column migration while the widening is still applied.
        $this->migration('2026_10_23_000000_add_organization_id_to_role_user_table')->down();

        $this->assertFalse(Schema::hasColumn('role_user', 'organization_id'));
        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    public function test_role_update_runs_in_a_transaction(): void
    {
        $actor = User::factory()->create(['organization_id' => $this->orgA->id]);
        $actor->roles()->attach($this->admin);
        $target = User::factory()->create(['organization_id' => $this->orgA->id]);
        $target->roles()->attach($this->admin);

        $begun = 0;
        Event::listen(TransactionBeginning::class, function () use (&$begun) {
            $begun++;
        });

        $this->actingAs($actor)->put("/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'roles' => [$this->viewer->id],
        ])->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, $begun);
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_failed_guard_rolls_back_name_change(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Original']);
        $admin->roles()->attach($this->admin);

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => 'Changed', 'email' => $admin->email, 'roles' => [$this->viewer->id],
        ])->assertSessionHasErrors('roles');

        $this->assertSame('Original', $admin->fresh()->name);
    }

    public function test_last_admin_guard_ignores_admin_grants_from_other_orgs(): void
    {
        $target = User::factory()->create(['organization_id' => $this->orgA->id]);
        $target->roles()->attach($this->admin);
        // An admin in org B, plus a stray org-A grant for a user now in org B.
        $stray = User::factory()->create(['organization_id' => $this->orgB->id]);
        DB::table('role_user')->insert(['role_id' => $this->admin->id, 'user_id' => $stray->id, 'organization_id' => $this->orgA->id]);
        $actor = User::factory()->create(['organization_id' => $this->orgA->id]);

        $guard = new ReflectionMethod(UserController::class, 'guardAdminRemoval');

        $this->expectException(ValidationException::class);
        $guard->invoke(new UserController, $actor, $target, [$this->viewer->id]);
    }
}
