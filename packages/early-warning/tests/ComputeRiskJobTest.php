<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\EarlyWarning\Hydrology\RiskCalculator;
use Lumina\EarlyWarning\Jobs\ComputeRiskJob;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #61: compute org risk assessments from global forecasts. */
class ComputeRiskJobTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

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
            'version' => '0.3.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $this->org = Organization::factory()->create();
        $row = new OrganizationPlugin;
        $row->forceFill([
            'organization_id' => $this->org->id,
            'plugin_key' => 'early-warning',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();
    }

    public function test_computes_hourly_assessments_and_seeds_subscriptions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
            'area_km2' => 12.5,
            'mean_slope_deg' => 6.2,
            'outlet_lon' => 13.03,
            'outlet_lat' => 32.83,
        ]);

        foreach (range(0, 5) as $h) {
            Forecast::query()->create([
                'basin_code' => 'TRP-HIRA',
                'source' => 'open-meteo',
                'fetched_at' => now(),
                'forecast_at' => now()->addHours($h),
                'precipitation_mm' => $h === 2 ? 25.0 : 1.0,
            ]);
        }

        (new ComputeRiskJob)->handle(
            app(RiskCalculator::class),
            app(PluginManager::class),
        );

        $this->assertSame(1, Subscription::query()->where('organization_id', $this->org->id)->count());
        $this->assertGreaterThan(0, RiskAssessment::query()->where('organization_id', $this->org->id)->count());
        $row = RiskAssessment::query()->where('forecast_at', '2026-10-25 14:00:00')->first();
        $this->assertNotNull($row);
        $this->assertContains($row->risk_level, ['green', 'yellow', 'orange', 'red']);
        $this->assertIsArray($row->inputs);

        // Idempotent
        (new ComputeRiskJob)->handle(
            app(RiskCalculator::class),
            app(PluginManager::class),
        );
        $count = RiskAssessment::query()->count();
        (new ComputeRiskJob)->handle(
            app(RiskCalculator::class),
            app(PluginManager::class),
        );
        $this->assertSame($count, RiskAssessment::query()->count());

        Carbon::setTestNow();
    }

    public function test_skips_when_plugin_not_installed(): void
    {
        InstalledPlugin::query()->delete();
        app(PluginManager::class)->forgetInstalled();

        (new ComputeRiskJob)->handle(
            app(RiskCalculator::class),
            app(PluginManager::class),
        );

        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_artisan_sync_command_runs_job(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        Basin::query()->create([
            'basin_code' => 'TRP-RABIE',
            'name' => 'Wadi Al-Rabie',
            'region' => 'tripoli',
            'area_km2' => 53,
            'mean_slope_deg' => 4,
        ]);
        Forecast::query()->create([
            'basin_code' => 'TRP-RABIE',
            'source' => 'open-meteo',
            'fetched_at' => now(),
            'forecast_at' => now(),
            'precipitation_mm' => 0.5,
        ]);

        $this->artisan('early-warning:compute-risk', ['--sync' => true])->assertExitCode(0);
        $this->assertGreaterThan(0, RiskAssessment::query()->count());

        Carbon::setTestNow();
    }
}
