<?php

namespace Tests\Feature\Plugins;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\PluginEvent;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/**
 * #54 plugin registry (ADR-0001 rev 4: D1, D3, D9, §5, §12 Gating).
 */
class PluginRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'early-warning';

    protected Organization $orgA;

    protected Organization $orgB;

    protected Role $admin;

    protected PluginManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->plugins = app(PluginManager::class);

        // A stand-in plugin route wired the way PluginServiceProvider will (§4).
        Route::middleware(['web', 'auth', 'organization', 'plugin:'.self::KEY])
            ->get('/x/'.self::KEY.'/ping', fn () => 'pong');
        Route::middleware(['web', 'auth', 'organization', 'plugin:'.self::KEY])
            ->get('/api/x/'.self::KEY.'/ping', fn () => response()->json(['ok' => true]));
        Route::middleware(['web', 'auth', 'super-admin'])
            ->get('/platform/extensions-probe', fn () => 'platform');
    }

    protected function install(string $status = InstalledPlugin::STATUS_INSTALLED): void
    {
        $row = new InstalledPlugin;
        $row->forceFill([
            'plugin_key' => self::KEY, 'version' => '0.1.0', 'status' => $status, 'installed_at' => now(),
        ])->save();
    }

    protected function userIn(?Organization $org, bool $admin = false, bool $super = false): User
    {
        $user = User::factory()->create(['organization_id' => $org?->id]);
        $user->forceFill(['is_super_admin' => $super])->save();
        if ($admin) {
            $user->roles()->attach($this->admin);
        }

        return $user->fresh();
    }

    public function test_registry_discovers_early_warning_from_composer(): void
    {
        $manifest = $this->plugins->manifest(self::KEY);

        $this->assertNotNull($manifest, 'packages/early-warning must be a composer dependency');
        $this->assertSame('lumina/early-warning', $manifest['package']);
        $this->assertTrue($this->plugins->isPresent(self::KEY));
        $this->assertFalse($this->plugins->isInstalled(self::KEY));
    }

    public function test_plugin_route_404_when_not_installed(): void
    {
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);

        $this->actingAs($this->userIn($this->orgA))->get('/x/early-warning/ping')->assertNotFound();
    }

    public function test_plugin_route_404_when_installed_but_disabled_for_org(): void
    {
        $this->install();

        $this->actingAs($this->userIn($this->orgA))->get('/x/early-warning/ping')->assertNotFound();
    }

    public function test_plugin_route_ok_when_installed_and_enabled_for_org(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);

        $this->actingAs($this->userIn($this->orgA))->get('/x/early-warning/ping')->assertOk()->assertSee('pong');
    }

    public function test_plugin_route_404_for_other_org_and_json_under_api(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $userB = $this->userIn($this->orgB);

        $this->actingAs($userB)->get('/x/early-warning/ping')->assertNotFound();
        $this->actingAs($userB)->get('/api/x/early-warning/ping')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_plugin_route_404_while_upgrading_or_failed_or_uninstalled(): void
    {
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $user = $this->userIn($this->orgA);

        foreach ([InstalledPlugin::STATUS_UPGRADING, InstalledPlugin::STATUS_FAILED, InstalledPlugin::STATUS_UNINSTALLED] as $status) {
            InstalledPlugin::query()->get()->each->delete();
            $this->install($status);
            $this->actingAs($user)->get('/x/early-warning/ping')->assertNotFound();
        }
    }

    public function test_disabling_busts_cache_and_returns_404(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $user = $this->userIn($this->orgA);
        $this->actingAs($user)->get('/x/early-warning/ping')->assertOk();

        $this->plugins->setEnabled(self::KEY, $this->orgA, false);
        $this->actingAs($user)->get('/x/early-warning/ping')->assertNotFound();
    }

    public function test_super_admin_follows_own_org_on_plugin_routes(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);

        // Own org enabled: allowed.
        $this->actingAs($this->userIn($this->orgA, super: true))->get('/x/early-warning/ping')->assertOk();
        // Own org disabled (plugin only on in org A): 404, no bypass.
        $this->actingAs($this->userIn($this->orgB, super: true))->get('/x/early-warning/ping')->assertNotFound();
    }

    public function test_super_admin_without_org_is_redirected_to_org_setup_on_web(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $super = $this->userIn(null, super: true);

        $this->actingAs($super)->get('/x/early-warning/ping')->assertRedirect(route('organization.setup'));
        $this->actingAs($super)->get('/x/early-warning/ping', ['X-Inertia' => 'true'])->assertRedirect(route('organization.setup'));
        $this->actingAs($super)->get(route('organization.setup'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Organization/Setup'));
    }

    public function test_super_admin_without_org_gets_403_on_json_requests(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $super = $this->userIn(null, super: true);

        $this->actingAs($super)->getJson('/x/early-warning/ping')->assertForbidden();
        $this->actingAs($super)->get('/api/x/early-warning/ping', ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_ordinary_user_without_org_gets_403_with_contact_admin_message_on_web(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $user = $this->userIn(null);

        $response = $this->actingAs($user)->get('/x/early-warning/ping')->assertForbidden();
        $this->assertStringContainsString('Contact your administrator', $response->exception->getMessage());

        $this->actingAs($user)->getJson('/x/early-warning/ping')->assertForbidden()
            ->assertJsonFragment(['message' => 'You are not a member of an organization. Contact your administrator.']);
    }

    public function test_early_warning_provider_extends_core_plugin_base(): void
    {
        $provider = new EarlyWarningServiceProvider($this->app);

        $this->assertInstanceOf(PluginServiceProvider::class, $provider);
        $this->assertSame(self::KEY, $provider->key());
        $this->assertSame($this->plugins->manifest(self::KEY)['key'], $provider->key());
        $this->assertDirectoryExists($provider::migrationsPath());
        $this->assertArrayHasKey('early-warning:link-basins', Artisan::all());
    }

    public function test_super_admin_cross_org_bypass_only_for_platform_toggle(): void
    {
        $super = $this->userIn($this->orgB, super: true);
        $adminA = $this->userIn($this->orgA, admin: true);
        $member = $this->userIn($this->orgA);

        $this->assertTrue(Gate::forUser($super)->allows('plugins.install'));
        $this->assertTrue(Gate::forUser($super)->allows('plugins.toggle', $this->orgA));
        $this->assertFalse(Gate::forUser($adminA)->allows('plugins.install'));
        $this->assertTrue(Gate::forUser($adminA)->allows('plugins.toggle', $this->orgA));
        $this->assertFalse(Gate::forUser($adminA)->allows('plugins.toggle', $this->orgB));
        $this->assertFalse(Gate::forUser($member)->allows('plugins.toggle', $this->orgA));

        $this->actingAs($super)->get('/platform/extensions-probe')->assertOk();
        $this->actingAs($adminA)->get('/platform/extensions-probe')->assertForbidden();
    }

    public function test_enabled_organizations_and_enabled_for(): void
    {
        $this->assertCount(0, $this->plugins->enabledOrganizations(self::KEY));
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $this->plugins->setEnabled(self::KEY, $this->orgB, false);

        $this->assertSame([$this->orgA->id], $this->plugins->enabledOrganizations(self::KEY)->all());
        $this->assertArrayHasKey(self::KEY, $this->plugins->enabledFor($this->orgA));
        $this->assertSame([], $this->plugins->enabledFor($this->orgB));
        $this->assertSame([], $this->plugins->enabledFor(null));
    }

    public function test_organization_plugins_unique_per_org_and_key(): void
    {
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $this->plugins->setEnabled(self::KEY, $this->orgA, false);

        $this->assertDatabaseCount('organization_plugins', 1);
    }

    public function test_make_super_admin_command_grants_and_revokes(): void
    {
        $user = $this->userIn($this->orgA);

        $this->artisan('lumina:make-super-admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->isSuperAdmin());

        $other = $this->userIn($this->orgA);
        $this->artisan('lumina:make-super-admin', ['email' => $other->email, '--actor' => $user->email])->assertSuccessful();

        $this->artisan('lumina:make-super-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isSuperAdmin());

        // Last super admin cannot be removed.
        $this->artisan('lumina:make-super-admin', ['email' => $other->email, '--revoke' => true])
            ->expectsOutputToContain('last super admin')->assertFailed();
        $this->assertTrue($other->fresh()->isSuperAdmin());

        $events = PluginEvent::query()->where('plugin_key', 'core')->orderBy('id')->get();
        $this->assertSame(['super-admin.grant', 'super-admin.grant', 'super-admin.revoke'], $events->pluck('action')->all());
        $this->assertSame('cli', $events[0]->meta['actor']);
        $this->assertSame($user->id, $events[1]->actor_id);

        $this->artisan('lumina:make-super-admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_is_super_admin_is_not_mass_assignable_or_settable_over_http(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'is_super_admin' => true]);
        $this->assertFalse((bool) $user->is_super_admin);

        $admin = $this->userIn($this->orgA, admin: true);
        $member = $this->userIn($this->orgA);
        $this->actingAs($admin)->put("/users/{$member->id}", [
            'name' => $member->name, 'email' => $member->email, 'is_super_admin' => true,
        ]);
        $this->assertFalse($member->fresh()->isSuperAdmin());

        auth()->logout();
        $this->post('/register', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'is_super_admin' => true,
        ]);
        $this->assertFalse((bool) User::where('email', 'new@example.com')->value('is_super_admin'));
    }

    public function test_plugin_cache_and_clear_commands(): void
    {
        $this->artisan('plugin:cache')->expectsOutputToContain('Plugin registry cached')->assertSuccessful();
        $this->artisan('plugin:clear')->assertSuccessful();
    }

    public function test_registry_ignores_non_plugin_packages(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'installed');
        file_put_contents($path, json_encode(['packages' => [
            ['name' => 'acme/lib'],
            ['name' => 'acme/bad', 'extra' => ['lumina' => ['name' => 'no key']]],
            ['name' => 'acme/hello', 'extra' => ['lumina' => ['key' => 'hello', 'version' => '1.0.0']]],
        ]]));

        $registry = (new PluginManager($path))->registry();
        unlink($path);

        $this->assertSame(['hello'], array_keys($registry));
    }
}
