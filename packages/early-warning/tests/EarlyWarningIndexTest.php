<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Console module home must be an Inertia page, not bare JSON. */
class EarlyWarningIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => EarlyWarningServiceProvider::migrationsPath(),
            '--realpath' => true,
        ])->assertExitCode(0);

        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'early-warning',
            'version' => '0.4.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $org->id]);
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $org->id,
            'plugin_key' => 'early-warning',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($org->id);
        app(PluginManager::class)->forgetInstalled();

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
        ]);
    }

    public function test_index_returns_inertia_page(): void
    {
        $this->actingAs($this->user)
            ->get('/x/early-warning')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EarlyWarning/Index')
                ->where('plugin', 'early-warning')
                ->has('advisory')
                ->where('authority', null)
                ->has('basins')
                ->has('mapBuilderUrl')
            );
    }

    public function test_index_still_serves_json_when_requested(): void
    {
        $this->actingAs($this->user)
            ->getJson('/x/early-warning')
            ->assertOk()
            ->assertJsonPath('plugin', 'early-warning')
            ->assertJsonPath('basins', 1);
    }
}
