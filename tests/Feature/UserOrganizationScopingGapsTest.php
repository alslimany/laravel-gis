<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\SolutionPackDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** #66 QA gap coverage: IDOR, param leaks, pack scoping, migration down. */
class UserOrganizationScopingGapsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected Role $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
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

    protected function pack(Organization $org, string $slug): SolutionPackDefinition
    {
        return SolutionPackDefinition::create([
            'organization_id' => $org->id, 'user_id' => $this->userIn($org)->id,
            'slug' => $slug, 'name' => 'Pack '.$slug, 'description' => 'd', 'definition' => [],
        ]);
    }

    public function test_cross_org_role_assignment_is_forbidden_and_no_grant_written(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $foreign = $this->userIn($this->orgB);
        $this->actingAs($adminA)->put("/users/{$foreign->id}", [
            'name' => $foreign->name, 'email' => $foreign->email, 'roles' => [$this->admin->id],
        ])->assertForbidden();
        $this->assertFalse($foreign->fresh()->roles()->exists());
    }

    public function test_show_route_does_not_exist_and_unknown_id_is_404(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $foreign = $this->userIn($this->orgB);
        $this->assertNotContains($this->actingAs($adminA)->get("/users/{$foreign->id}")->status(), [200]);
        $this->actingAs($adminA)->get('/users/999999/edit')->assertNotFound();
    }

    public function test_index_query_params_cannot_leak_other_org(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $foreign = $this->userIn($this->orgB);
        foreach ([
            '?organization_id='.$this->orgB->id,
            '?search='.urlencode($foreign->email),
            '?filter[organization_id]='.$this->orgB->id,
            '?page=2&per_page=1000',
        ] as $qs) {
            $this->actingAs($adminA)->get('/users'.$qs)->assertOk()
                ->assertInertia(fn ($p) => $p->where('users.data', fn ($d) => collect($d)->every(fn ($u) => $u['organization_id'] === $this->orgA->id)));
        }
    }

    public function test_update_with_organization_id_payload_does_not_move_user(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $self = $adminA;
        $this->actingAs($adminA)->put("/users/{$self->id}", [
            'name' => 'Self', 'email' => $self->email, 'organization_id' => $this->orgB->id,
        ])->assertRedirect();
        $this->assertSame($this->orgA->id, (int) $self->fresh()->organization_id);
    }

    public function test_registration_ignores_organization_id(): void
    {
        $this->post('/register', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'organization_id' => $this->orgB->id,
        ]);
        $this->assertNull(User::where('email', 'new@example.com')->value('organization_id'));
    }

    public function test_solution_pack_edit_update_delete_other_org_is_404(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $packB = $this->pack($this->orgB, 'b-pack');
        $this->actingAs($adminA)->get("/solution-packs/{$packB->id}/edit")->assertNotFound();
        $this->actingAs($adminA)->put("/solution-packs/{$packB->id}", [
            'slug' => 'b-pack-x', 'name' => 'x',
            'definition' => \App\SolutionPacks\SolutionPack::all()[0]->definition(),
        ])->assertNotFound();
        $this->actingAs($adminA)->delete("/solution-packs/{$packB->id}")->assertNotFound();
        $this->assertDatabaseHas('solution_pack_definitions', ['id' => $packB->id, 'name' => 'Pack b-pack']);
    }

    public function test_solution_pack_index_and_install_and_duplicate_ignore_other_org(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $this->pack($this->orgB, 'secret-b');
        $res = $this->actingAs($adminA)->get('/solution-packs?organization_id='.$this->orgB->id)->assertOk();
        $this->assertStringNotContainsString('secret-b', $res->getContent());
        $this->actingAs($adminA)->post('/organization/solution-packs/secret-b', ['organization_id' => $this->orgB->id])->assertNotFound();
        $this->actingAs($adminA)->post('/solution-packs/secret-b/duplicate')->assertNotFound();
    }

    public function test_store_ignores_organization_id_param(): void
    {
        $adminA = $this->userIn($this->orgA, $this->admin);
        $this->actingAs($adminA)->post('/solution-packs', [
            'slug' => 'mine', 'name' => 'Mine', 'organization_id' => $this->orgB->id, 'definition' => [],
        ]);
        $this->assertSame(0, SolutionPackDefinition::where('organization_id', $this->orgB->id)->count());
    }

    public function test_migration_down_removes_column_and_up_is_rerunnable(): void
    {
        $m = require database_path('migrations/2026_10_23_000000_add_organization_id_to_role_user_table.php');
        $m->down();
        $this->assertFalse(Schema::hasColumn('role_user', 'organization_id'));
        $m->up();
        $this->assertTrue(Schema::hasColumn('role_user', 'organization_id'));
    }
}
