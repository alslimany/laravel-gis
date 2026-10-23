<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #66 follow-ups QA: refused role updates leave no partial changes. */
class UserOrganizationFollowupsQaTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Role $admin;

    protected Role $editor;

    protected Role $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->editor = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $this->viewer = Role::create(['name' => 'viewer', 'description' => 'Viewer']);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    /** @return array<int, array<string, mixed>> */
    protected function snapshot(User $u): array
    {
        return [
            $u->fresh()->only(['name', 'email', 'organization_id']),
            \DB::table('role_user')->where('user_id', $u->id)->orderBy('id')->get(['role_id', 'organization_id'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    protected function seedTarget(): User
    {
        $t = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Orig', 'email' => 'orig@qa.test']);
        $t->roles()->attach($this->admin);
        $t->roles()->attach($this->editor);
        $t->roles()->attach($this->viewer, ['organization_id' => $this->orgB->id]);

        return $t;
    }

    public function test_last_admin_refusal_over_http_leaves_no_partial_changes(): void
    {
        $target = $this->seedTarget();
        $actor = User::factory()->create(['organization_id' => $this->orgA->id]);
        $actor->roles()->attach($this->admin);
        $before = $this->snapshot($target);

        // Real request and transaction. Over HTTP the acting admin always counts
        // as another admin, so the override drops the actor's grant *inside* the
        // transaction before the guard runs; that write must roll back too.
        $this->app->bind(UserController::class, fn () => new class extends UserController
        {
            protected function guardAdminRemoval(User $actor, User $user, array $roleIds): void
            {
                \DB::table('role_user')->where('user_id', $actor->id)->delete();
                parent::guardAdminRemoval($actor, $user, $roleIds);
            }
        });
        $actorBefore = $this->snapshot($actor);

        $this->actingAs($actor)->put("/users/{$target->id}", [
            'name' => 'Changed', 'email' => 'changed@qa.test', 'roles' => [$this->viewer->id],
        ])->assertSessionHasErrors(["roles" => "An organization must keep at least one admin."]);

        $this->assertSame($before, $this->snapshot($target));
        $this->assertSame($actorBefore, $this->snapshot($actor));
    }

    public function test_self_demotion_refusal_leaves_no_partial_changes(): void
    {
        $target = $this->seedTarget();
        User::factory()->create(['organization_id' => $this->orgA->id])->roles()->attach($this->admin);
        $before = $this->snapshot($target);

        $this->actingAs($target)->put("/users/{$target->id}", [
            'name' => 'Changed', 'email' => 'changed@qa.test', 'roles' => [$this->viewer->id],
        ])->assertSessionHasErrors('roles');

        $this->assertSame($before, $this->snapshot($target));
    }

    public function test_failure_during_role_sync_rolls_back_name_and_email(): void
    {
        $target = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Orig', 'email' => 'orig@qa.test']);
        $target->roles()->attach($this->editor);
        $target->roles()->attach($this->viewer, ['organization_id' => $this->orgB->id]);
        $actor = User::factory()->create(['organization_id' => $this->orgA->id]);
        $actor->roles()->attach($this->admin);
        $before = $this->snapshot($target);

        RoleUser::creating(function () {
            throw new \RuntimeException('boom during sync');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->put("/users/{$target->id}", [
                'name' => 'Changed', 'email' => 'changed@qa.test', 'roles' => [$this->viewer->id],
            ]);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom during sync', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($target));
    }
}
