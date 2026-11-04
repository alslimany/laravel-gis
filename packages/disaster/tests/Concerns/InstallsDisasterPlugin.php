<?php

namespace Lumina\Disaster\Tests\Concerns;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Lumina\Disaster\Providers\DisasterServiceProvider;

trait InstallsDisasterPlugin
{
    protected Organization $disasterOrg;

    protected User $disasterAdmin;

    protected function installDisasterPlugin(bool $enabled = true): void
    {
        $this->app->register(DisasterServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => DisasterServiceProvider::migrationsPath(),
            '--realpath' => true,
        ])->assertExitCode(0);

        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'disaster',
            'version' => '0.1.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $this->disasterOrg = Organization::factory()->create();
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['description' => 'Administrator'],
        );
        $this->disasterAdmin = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $this->disasterAdmin->roles()->attach($adminRole->id, ['organization_id' => $this->disasterOrg->id]);

        (new OrganizationPlugin)->forceFill([
            'organization_id' => $this->disasterOrg->id,
            'plugin_key' => 'disaster',
            'enabled' => $enabled,
            'enabled_at' => $enabled ? now() : null,
        ])->save();

        app(PluginManager::class)->forgetOrganization($this->disasterOrg->id);
        app(PluginManager::class)->forgetInstalled();
        app(PluginManager::class)->registerPermissionGates();
    }
}
