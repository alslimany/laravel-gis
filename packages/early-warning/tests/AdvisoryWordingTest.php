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
use Lumina\EarlyWarning\Support\AdvisoryCopy;
use Tests\TestCase;

/** Cross-cutting: UI/API copy stays advisory; optional official authority link. */
class AdvisoryWordingTest extends TestCase
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
            'version' => '0.9.0',
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

    public function test_default_advisory_states_not_an_official_warning(): void
    {
        $this->assertStringContainsString('not an official warning', AdvisoryCopy::message());
        $this->assertStringContainsString('decision support', AdvisoryCopy::message());
        $this->assertNull(AdvisoryCopy::authority());
    }

    public function test_index_exposes_advisory_and_configured_authority(): void
    {
        config([
            'early-warning.advisory.authority.name' => 'Libya Civil Defense',
            'early-warning.advisory.authority.url' => 'https://example.gov.ly/civil-defense',
        ]);

        $this->actingAs($this->user)
            ->get('/x/early-warning')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EarlyWarning/Index')
                ->where('advisory', AdvisoryCopy::message())
                ->where('authority.name', 'Libya Civil Defense')
                ->where('authority.url', 'https://example.gov.ly/civil-defense')
            );
    }

    public function test_warnings_api_includes_advisory_and_authority(): void
    {
        config([
            'early-warning.advisory.authority.name' => 'National Met Office',
            'early-warning.advisory.authority.url' => null,
        ]);

        $this->actingAs($this->user)
            ->getJson('/x/early-warning/warnings?hours=1')
            ->assertOk()
            ->assertJsonPath('advisory', AdvisoryCopy::message())
            ->assertJsonPath('authority.name', 'National Met Office')
            ->assertJsonPath('authority.url', null);
    }
}
