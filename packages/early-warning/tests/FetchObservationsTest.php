<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Hydrology\RiskCalculator;
use Lumina\EarlyWarning\Ingestion\NasaPowerClient;
use Lumina\EarlyWarning\Jobs\ComputeRiskJob;
use Lumina\EarlyWarning\Jobs\FetchObservationsJob;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\Observation;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Phase 2: IMERG/SMAP-class observations via NASA POWER + risk wiring. */
class FetchObservationsTest extends TestCase
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
            'version' => '0.7.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $this->org = Organization::factory()->create();
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $this->org->id,
            'plugin_key' => 'early-warning',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
            'outlet_lon' => 13.03,
            'outlet_lat' => 32.83,
            'mean_slope_deg' => 6,
            'area_km2' => 12,
        ]);
    }

    public function test_observations_table_is_global(): void
    {
        $this->assertTrue(Schema::hasTable('ew_observations'));
        $this->assertFalse(Schema::hasColumn('ew_observations', 'organization_id'));
    }

    public function test_nasa_power_client_parses_hourly_json(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([
                'properties' => [
                    'parameter' => [
                        'PRECTOTCORR' => [
                            '2026102412' => 1.5,
                            '2026102413' => -999,
                        ],
                        'GWETROOT' => [
                            '2026102412' => 0.62,
                            '2026102413' => 0.63,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $rows = app(NasaPowerClient::class)->fetchHourly(
            32.83,
            13.03,
            Carbon::parse('2026-10-24 00:00:00', 'UTC'),
            Carbon::parse('2026-10-24 23:00:00', 'UTC'),
        );

        $this->assertCount(2, $rows);
        $this->assertSame(1.5, $rows[0]['precipitation_mm']);
        $this->assertSame(0.62, $rows[0]['soil_moisture']);
        $this->assertNull($rows[1]['precipitation_mm']);
        $this->assertSame(0.63, $rows[1]['soil_moisture']);
    }

    public function test_fetch_observations_job_upserts_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([
                'properties' => [
                    'parameter' => [
                        'PRECTOTCORR' => ['2026102500' => 2.0],
                        'GWETROOT' => ['2026102500' => 0.55],
                    ],
                ],
            ], 200),
        ]);

        (new FetchObservationsJob)->handle(
            app(NasaPowerClient::class),
            app(PluginManager::class),
        );

        $this->assertSame(1, Observation::query()->count());
        $row = Observation::query()->first();
        $this->assertSame('nasa-power', $row->source);
        $this->assertSame(2.0, $row->precipitation_mm);
        $this->assertSame(0.55, $row->soil_moisture);

        Carbon::setTestNow();
    }

    public function test_compute_risk_uses_observed_soil_saturation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        foreach (range(0, 5) as $h) {
            Forecast::query()->create([
                'basin_code' => 'TRP-HIRA',
                'source' => 'open-meteo',
                'fetched_at' => now(),
                'forecast_at' => now()->addHours($h),
                'precipitation_mm' => 2.0,
            ]);
        }

        Observation::query()->create([
            'basin_code' => 'TRP-HIRA',
            'source' => 'nasa-power',
            'observed_at' => now()->subHour(),
            'fetched_at' => now(),
            'precipitation_mm' => 1.0,
            'soil_moisture' => 0.9,
        ]);

        (new ComputeRiskJob)->handle(
            app(RiskCalculator::class),
            app(PluginManager::class),
        );

        $assessment = RiskAssessment::query()
            ->where('organization_id', $this->org->id)
            ->where('forecast_at', '2026-10-25 12:00:00')
            ->first();

        $this->assertNotNull($assessment);
        $this->assertSame(0.9, $assessment->inputs['soil_saturation']);
        $this->assertSame('nasa-power', $assessment->inputs['observation_source']);

        Carbon::setTestNow();
    }

    public function test_artisan_sync_command(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([
                'properties' => ['parameter' => ['PRECTOTCORR' => [], 'GWETROOT' => []]],
            ], 200),
        ]);

        $this->artisan('early-warning:fetch-observations', ['--sync' => true])->assertExitCode(0);
    }
}
