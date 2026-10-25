<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\User;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #62: map-builder warnings feed. */
class WarningsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

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

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $this->org->id,
            'plugin_key' => 'early-warning',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();
    }

    public function test_warnings_returns_basins_and_timeline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
            'area_km2' => 12,
        ]);
        Subscription::query()->create([
            'organization_id' => $this->org->id,
            'basin_code' => 'TRP-HIRA',
        ]);
        Forecast::query()->create([
            'basin_code' => 'TRP-HIRA',
            'source' => 'open-meteo',
            'fetched_at' => now(),
            'forecast_at' => now(),
            'precipitation_mm' => 2.5,
        ]);
        RiskAssessment::query()->create([
            'organization_id' => $this->org->id,
            'basin_code' => 'TRP-HIRA',
            'forecast_at' => now(),
            'valid_from' => now(),
            'valid_until' => now()->addHour(),
            'risk_level' => 'yellow',
            'score' => 32,
            'inputs' => ['advisory' => true],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/x/early-warning/warnings?hours=3');

        $response->assertOk()
            ->assertJsonPath('plugin', 'early-warning')
            ->assertJsonPath('basins.0.basin_code', 'TRP-HIRA')
            ->assertJsonPath('basins.0.risk_level', 'yellow')
            ->assertJsonPath('basins.0.precip_mm', 2.5);

        $this->assertCount(3, $response->json('hours'));
        $this->assertCount(3, $response->json('basins.0.timeline'));
        $this->assertStringContainsString('not an official warning', $response->json('advisory'));

        Carbon::setTestNow();
    }

    public function test_warnings_404_when_plugin_disabled(): void
    {
        OrganizationPlugin::query()->where('organization_id', $this->org->id)->update(['enabled' => false]);
        app(PluginManager::class)->forgetOrganization($this->org->id);

        $this->actingAs($this->user)
            ->getJson('/x/early-warning/warnings')
            ->assertNotFound();
    }
}
