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
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** ComputeRiskJob must not overwrite stored risk for exercise/demo orgs (settings.exercise = true). */
class ComputeRiskSkipsExerciseOrgTest extends TestCase
{
    use RefreshDatabase;

    private function enable(Organization $org, ?array $settings = null): void
    {
        $row = new OrganizationPlugin;
        $row->forceFill(['organization_id' => $org->id, 'plugin_key' => 'early-warning', 'enabled' => true, 'enabled_at' => now()]);
        $row->settings = $settings;
        $row->save();
        app(PluginManager::class)->forgetOrganization($org->id);
    }

    public function test_exercise_org_keeps_stored_risk_while_normal_org_is_computed(): void
    {
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'early-warning', 'version' => '0.4.0', 'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(), 'meta' => [],
        ])->save();
        app(PluginManager::class)->forgetInstalled();
        Carbon::setTestNow(Carbon::parse('2026-11-10 02:00:00', 'UTC'));

        $normal = Organization::factory()->create();
        $exercise = Organization::factory()->create();
        $this->enable($normal);
        $this->enable($exercise, ['demo_key' => 'ncmcd-w26', 'exercise' => true]);

        Basin::query()->create(['basin_code' => 'TRP-HIRA', 'name' => 'Wadi Al-Hira', 'region' => 'tripoli', 'area_km2' => 10.5]);
        foreach (range(0, 3) as $h) {
            Forecast::query()->create([
                'basin_code' => 'TRP-HIRA', 'source' => 'open-meteo', 'fetched_at' => now(),
                'forecast_at' => now()->addHours($h), 'precipitation_mm' => 0.0,
            ]);
        }
        $stored = RiskAssessment::query()->create([
            'organization_id' => $exercise->id, 'basin_code' => 'TRP-HIRA', 'forecast_at' => now(),
            'valid_from' => now(), 'valid_until' => now()->addHour(), 'risk_level' => 'red', 'score' => 0.92,
            'inputs' => ['source' => 'ncmcd-demo'],
        ]);

        (new ComputeRiskJob)->handle(app(RiskCalculator::class), app(PluginManager::class));

        $this->assertGreaterThan(0, RiskAssessment::query()->where('organization_id', $normal->id)->count());
        $this->assertSame(1, RiskAssessment::query()->where('organization_id', $exercise->id)->count());
        $this->assertSame('red', $stored->fresh()->risk_level);
        $this->assertSame(['source' => 'ncmcd-demo'], $stored->fresh()->inputs);

        Carbon::setTestNow();
    }
}
