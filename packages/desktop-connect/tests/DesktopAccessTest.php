<?php

namespace Lumina\DesktopConnect\Tests;

use App\Models\Group;
use App\Models\InstalledPlugin;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginLifecycle;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Models\GrantEvent;
use Lumina\DesktopConnect\Policies\GrantPolicy;
use Lumina\DesktopConnect\Services\DesktopAccess;
use Tests\TestCase;

/**
 * DG-1.5: deny-by-default DesktopAccess::can + GrantPolicy (admin manage, cross-org denied).
 */
class DesktopAccessTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'desktop-connect';

    protected Organization $orgA;

    protected Organization $orgB;

    protected Role $admin;

    protected Role $editor;

    protected PluginManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->editor = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->plugins = app(PluginManager::class);

        app(PluginLifecycle::class)->install(self::KEY);
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $this->plugins->setEnabled(self::KEY, $this->orgB, true);
    }

    protected function userIn(Organization $org, bool $admin = false): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->roles()->attach($admin ? $this->admin : $this->editor);

        return $user->fresh();
    }

    protected function layerIn(Organization $org, array $attrs = []): Layer
    {
        return Layer::factory()->create(array_merge([
            'organization_id' => $org->id,
            'user_id' => User::factory()->create(['organization_id' => $org->id])->id,
            'published' => false,
        ], $attrs));
    }

    public function test_can_denies_by_default_without_grant(): void
    {
        $user = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);

        $this->assertFalse(DesktopAccess::can($user, $layer, Grant::SCOPE_READ));
        $this->assertFalse(DesktopAccess::can($user, $layer, Grant::SCOPE_EDIT));
    }

    public function test_can_allows_read_with_user_grant(): void
    {
        $user = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);
        $admin = $this->userIn($this->orgA, admin: true);

        Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $user->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_READ,
            'expires_at' => null,
            'granted_by' => $admin->id,
        ]);

        $this->assertTrue(DesktopAccess::can($user, $layer, Grant::SCOPE_READ));
        $this->assertFalse(DesktopAccess::can($user, $layer, Grant::SCOPE_EDIT));
    }

    public function test_edit_grant_implies_read(): void
    {
        $user = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);
        $admin = $this->userIn($this->orgA, admin: true);

        Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $user->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_EDIT,
            'expires_at' => null,
            'granted_by' => $admin->id,
        ]);

        $this->assertTrue(DesktopAccess::can($user, $layer, Grant::SCOPE_READ));
        $this->assertTrue(DesktopAccess::can($user, $layer, Grant::SCOPE_EDIT));
    }

    public function test_can_respects_group_grant(): void
    {
        $user = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);
        $admin = $this->userIn($this->orgA, admin: true);
        $group = Group::query()->create([
            'organization_id' => $this->orgA->id,
            'name' => 'GIS',
        ]);
        $user->groups()->attach($group);

        Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => null,
            'group_id' => $group->id,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_READ,
            'expires_at' => null,
            'granted_by' => $admin->id,
        ]);

        $this->assertTrue(DesktopAccess::can($user, $layer, Grant::SCOPE_READ));
    }

    public function test_can_denies_cross_org_even_with_grant_row(): void
    {
        $userA = $this->userIn($this->orgA);
        $layerB = $this->layerIn($this->orgB);
        $adminB = $this->userIn($this->orgB, admin: true);

        // Mis-issued row must still fail DesktopAccess (defence in depth).
        Grant::query()->create([
            'organization_id' => $this->orgB->id,
            'user_id' => $userA->id,
            'group_id' => null,
            'layer_id' => $layerB->id,
            'scope' => Grant::SCOPE_EDIT,
            'expires_at' => null,
            'granted_by' => $adminB->id,
        ]);

        $this->assertFalse(DesktopAccess::can($userA, $layerB, Grant::SCOPE_READ));
        $this->assertFalse(DesktopAccess::can($userA, $layerB, Grant::SCOPE_EDIT));
    }

    public function test_can_denies_expired_grant(): void
    {
        $user = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);
        $admin = $this->userIn($this->orgA, admin: true);

        Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $user->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_READ,
            'expires_at' => now()->subHour(),
            'granted_by' => $admin->id,
        ]);

        $this->assertFalse(DesktopAccess::can($user, $layer, Grant::SCOPE_READ));
    }

    public function test_can_denies_edit_on_sql_view_layer(): void
    {
        $user = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA, [
            'metadata' => ['sql_view' => true],
        ]);
        $admin = $this->userIn($this->orgA, admin: true);

        Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $user->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_EDIT,
            'expires_at' => null,
            'granted_by' => $admin->id,
        ]);

        $this->assertFalse(DesktopAccess::can($user, $layer, Grant::SCOPE_EDIT));
        // Read still allowed via edit grant scopes list, but edit path is blocked by writable check.
        // Read with only an edit-scoped grant: hasActiveGrant includes edit for read — layer writable N/A for read.
        $this->assertTrue(DesktopAccess::can($user, $layer, Grant::SCOPE_READ));
    }

    public function test_policy_only_org_admin_can_manage(): void
    {
        $policy = new GrantPolicy;
        $admin = $this->userIn($this->orgA, admin: true);
        $editor = $this->userIn($this->orgA);
        $otherAdmin = $this->userIn($this->orgB, admin: true);
        $layer = $this->layerIn($this->orgA);

        $grant = Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $editor->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_READ,
            'expires_at' => null,
            'granted_by' => $admin->id,
        ]);

        $this->assertTrue($policy->create($admin));
        $this->assertFalse($policy->create($editor));
        $this->assertTrue($policy->delete($admin, $grant));
        $this->assertFalse($policy->delete($editor, $grant));
        $this->assertFalse($policy->delete($otherAdmin, $grant), 'cross-org admin cannot manage');
        $this->assertFalse($policy->view($otherAdmin, $grant), 'cross-org denied for view');
    }

    public function test_admin_can_create_and_revoke_via_http(): void
    {
        $admin = $this->userIn($this->orgA, admin: true);
        $member = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);

        $this->actingAs($admin)
            ->post('/x/desktop-connect/grants', [
                'layer_id' => $layer->id,
                'scope' => 'read',
                'user_id' => $member->id,
            ])
            ->assertRedirect(route('desktop-connect.grants.index'));

        $grant = Grant::query()->where('layer_id', $layer->id)->where('user_id', $member->id)->first();
        $this->assertNotNull($grant);
        $this->assertSame(1, GrantEvent::query()->where('action', GrantEvent::ACTION_GRANTED)->count());

        $this->actingAs($admin)
            ->delete('/x/desktop-connect/grants/'.$grant->id)
            ->assertRedirect(route('desktop-connect.grants.index'));

        $this->assertNull(Grant::query()->find($grant->id));
        $this->assertSame(1, GrantEvent::query()->where('action', GrantEvent::ACTION_REVOKED)->count());
    }

    public function test_non_admin_cannot_create_grant(): void
    {
        $editor = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);

        $this->actingAs($editor)
            ->post('/x/desktop-connect/grants', [
                'layer_id' => $layer->id,
                'scope' => 'read',
                'user_id' => $editor->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, Grant::query()->count());
    }

    public function test_cross_org_admin_cannot_revoke(): void
    {
        $adminA = $this->userIn($this->orgA, admin: true);
        $adminB = $this->userIn($this->orgB, admin: true);
        $member = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);

        $grant = Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $member->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_READ,
            'expires_at' => null,
            'granted_by' => $adminA->id,
        ]);

        $this->actingAs($adminB)
            ->delete('/x/desktop-connect/grants/'.$grant->id)
            ->assertForbidden();

        $this->assertNotNull(Grant::query()->find($grant->id));
    }

    public function test_edit_grant_rejected_for_sql_view_layer(): void
    {
        $admin = $this->userIn($this->orgA, admin: true);
        $member = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA, [
            'metadata' => ['sql_view' => true],
        ]);

        $this->actingAs($admin)
            ->from(route('desktop-connect.grants.index'))
            ->post('/x/desktop-connect/grants', [
                'layer_id' => $layer->id,
                'scope' => 'edit',
                'user_id' => $member->id,
            ])
            ->assertRedirect(route('desktop-connect.grants.index'))
            ->assertSessionHasErrors('scope');

        $this->assertSame(0, Grant::query()->count());
    }

    public function test_grants_index_renders_for_admin(): void
    {
        $admin = $this->userIn($this->orgA, admin: true);
        $member = $this->userIn($this->orgA);
        $layer = $this->layerIn($this->orgA);

        $grant = Grant::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $member->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => Grant::SCOPE_READ,
            'expires_at' => null,
            'granted_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/x/desktop-connect/grants')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DesktopConnect/Index')
                ->has('grants', 1)
                ->where('can_manage', true)
                ->where('store_url', route('desktop-connect.grants.store'))
                ->where('index_url', route('desktop-connect.grants.index'))
                ->where('grants.0.destroy_url', route('desktop-connect.grants.destroy', $grant))
                ->missing('public_id_pending')
                ->has('layers.0.public_id')
                ->has('layers.0.alias')
                ->where('layers.0.id', $layer->id)
                ->where('layers.0.public_id', $layer->fresh()->public_id)
                ->where('layers.0.alias', $layer->fresh()->alias));
    }

    public function test_grants_index_hides_manage_for_non_admin(): void
    {
        $editor = $this->userIn($this->orgA);

        $this->actingAs($editor)
            ->get('/x/desktop-connect/grants')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DesktopConnect/Index')
                ->where('can_manage', false));
    }

    public function test_admin_can_create_group_grant_via_http(): void
    {
        $admin = $this->userIn($this->orgA, admin: true);
        $layer = $this->layerIn($this->orgA);
        $group = Group::query()->create([
            'organization_id' => $this->orgA->id,
            'name' => 'Field crew',
        ]);

        $this->actingAs($admin)
            ->post('/x/desktop-connect/grants', [
                'layer_id' => $layer->id,
                'scope' => 'read',
                'group_id' => $group->id,
            ])
            ->assertRedirect(route('desktop-connect.grants.index'));

        $grant = Grant::query()->where('layer_id', $layer->id)->where('group_id', $group->id)->first();
        $this->assertNotNull($grant);
        $this->assertNull($grant->user_id);
    }
}
