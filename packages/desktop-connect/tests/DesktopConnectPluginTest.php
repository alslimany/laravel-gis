<?php

namespace Lumina\DesktopConnect\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginLifecycle;
use App\Plugins\PluginManager;
use App\Plugins\PluginServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\DesktopConnect\Providers\DesktopConnectServiceProvider;
use Tests\TestCase;

/**
 * DG-1.4: desktop-connect scaffold — registry, install, and 404 when disabled (ADR-0001 D3/D5).
 */
class DesktopConnectPluginTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'desktop-connect';

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
    }

    protected function install(string $status = InstalledPlugin::STATUS_INSTALLED): void
    {
        (new InstalledPlugin)->forceFill([
            'plugin_key' => self::KEY,
            'version' => '0.1.0',
            'status' => $status,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $this->plugins->forgetInstalled();
    }

    protected function userIn(?Organization $org, bool $admin = false): User
    {
        $user = User::factory()->create(['organization_id' => $org?->id]);
        if ($admin) {
            $user->roles()->attach($this->admin);
        }

        return $user->fresh();
    }

    public function test_registry_discovers_desktop_connect_from_composer(): void
    {
        $manifest = $this->plugins->manifest(self::KEY);

        $this->assertNotNull($manifest, 'packages/desktop-connect must be a composer dependency');
        $this->assertSame('lumina/desktop-connect', $manifest['package']);
        $this->assertSame('Desktop GIS Connect', $manifest['name']);
        $this->assertSame(
            'Lumina\\DesktopConnect\\Providers\\DesktopConnectServiceProvider',
            $manifest['provider'],
        );
        $this->assertContains('desktop-connect.view', $manifest['permissions']);
        $this->assertContains('desktop-connect.manage', $manifest['permissions']);
        $this->assertSame('Desktop connections', $manifest['nav'][0]['label_en']);
        $this->assertSame('اتصالات سطح المكتب', $manifest['nav'][0]['label_ar']);
        $this->assertTrue($this->plugins->isPresent(self::KEY));
        $this->assertFalse($this->plugins->isInstalled(self::KEY));
    }

    public function test_provider_extends_core_plugin_base(): void
    {
        $provider = new DesktopConnectServiceProvider($this->app);

        $this->assertInstanceOf(PluginServiceProvider::class, $provider);
        $this->assertSame(self::KEY, $provider->key());
        $this->assertDirectoryExists($provider::migrationsPath());
    }

    public function test_plugin_route_404_when_not_installed(): void
    {
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);

        $this->actingAs($this->userIn($this->orgA))->get('/x/desktop-connect/ping')->assertNotFound();
    }

    public function test_plugin_route_404_when_installed_but_disabled_for_org(): void
    {
        $this->install();

        $this->assertFalse(
            (bool) OrganizationPlugin::query()
                ->where('organization_id', $this->orgA->id)
                ->where('plugin_key', self::KEY)
                ->value('enabled'),
            'plugins default off for orgs (ADR-0001 D5)',
        );

        $this->actingAs($this->userIn($this->orgA))->get('/x/desktop-connect/ping')->assertNotFound();
        $this->actingAs($this->userIn($this->orgA))->get('/x/desktop-connect')->assertNotFound();
    }

    public function test_plugin_route_ok_when_installed_and_enabled_for_org(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);

        $this->actingAs($this->userIn($this->orgA))
            ->get('/x/desktop-connect/ping')
            ->assertOk()
            ->assertJson(['pong' => true]);

        $this->actingAs($this->userIn($this->orgA))
            ->get('/x/desktop-connect')
            ->assertOk()
            ->assertJson(['plugin' => 'desktop-connect']);
    }

    public function test_plugin_route_404_for_other_org(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);

        $this->actingAs($this->userIn($this->orgB))->get('/x/desktop-connect/ping')->assertNotFound();
    }

    public function test_disabling_returns_404(): void
    {
        $this->install();
        $this->plugins->setEnabled(self::KEY, $this->orgA, true);
        $user = $this->userIn($this->orgA);
        $this->actingAs($user)->get('/x/desktop-connect/ping')->assertOk();

        $this->plugins->setEnabled(self::KEY, $this->orgA, false);
        $this->actingAs($user)->get('/x/desktop-connect/ping')->assertNotFound();
    }

    public function test_plugin_install_command_marks_installed_and_runs_dc_migrations(): void
    {
        $this->assertFalse($this->plugins->isInstalled(self::KEY));

        app(PluginLifecycle::class)->install(self::KEY);

        $this->assertTrue($this->plugins->isInstalled(self::KEY));
        $this->assertSame(
            InstalledPlugin::STATUS_INSTALLED,
            InstalledPlugin::query()->where('plugin_key', self::KEY)->value('status'),
        );
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('dc_grants'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('dc_grant_events'));

        // Still off for orgs until explicitly enabled.
        $this->actingAs($this->userIn($this->orgA))->get('/x/desktop-connect/ping')->assertNotFound();
    }
}
