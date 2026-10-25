<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lumina\EarlyWarning\Ingestion\OpenMeteoClient;
use Lumina\EarlyWarning\Jobs\FetchForecastsJob;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #60: Open-Meteo hourly ingest into global ew_forecasts. */
class FetchForecastsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => EarlyWarningServiceProvider::migrationsPath(),
            '--realpath' => true,
        ])->assertExitCode(0);

        $row = new InstalledPlugin;
        $row->forceFill([
            'plugin_key' => 'early-warning',
            'version' => '0.1.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();
    }

    public function test_fetches_and_upserts_hourly_rows_per_basin(): void
    {
        Basin::query()->create([
            'basin_code' => 'TRP-MAJENIN',
            'name' => 'Wadi Al-Majenin',
            'region' => 'tripoli',
            'outlet_lon' => 13.182156,
            'outlet_lat' => 32.891883,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.open-meteo.com/*' => Http::response([
                'latitude' => 32.89,
                'longitude' => 13.18,
                'hourly' => [
                    'time' => ['2026-10-25T00:00', '2026-10-25T01:00'],
                    'precipitation' => [0.0, 1.5],
                    'precipitation_probability' => [10, 40],
                    'temperature_2m' => [22.1, 21.8],
                ],
            ], 200),
        ]);

        (new FetchForecastsJob)->handle(
            app(OpenMeteoClient::class),
            app(PluginManager::class),
        );

        $this->assertSame(2, Forecast::query()->count());
        $row = Forecast::query()->where('forecast_at', '2026-10-25 01:00:00')->first();
        $this->assertNotNull($row);
        $this->assertSame('TRP-MAJENIN', $row->basin_code);
        $this->assertSame('open-meteo', $row->source);
        $this->assertEquals(1.5, (float) $row->precipitation_mm);
        $this->assertSame(40, $row->precipitation_probability);

        // Idempotent upsert
        (new FetchForecastsJob)->handle(
            app(OpenMeteoClient::class),
            app(PluginManager::class),
        );
        $this->assertSame(2, Forecast::query()->count());

        Http::assertSentCount(2);
    }

    public function test_skips_when_plugin_not_installed(): void
    {
        InstalledPlugin::query()->delete();
        app(PluginManager::class)->forgetInstalled();

        Http::fake();
        (new FetchForecastsJob)->handle(
            app(OpenMeteoClient::class),
            app(PluginManager::class),
        );

        $this->assertSame(0, Forecast::query()->count());
        Http::assertNothingSent();
    }

    public function test_artisan_sync_command_runs_job(): void
    {
        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
            'outlet_lon' => 13.03,
            'outlet_lat' => 32.83,
        ]);

        Http::fake([
            'api.open-meteo.com/*' => Http::response([
                'latitude' => 32.83,
                'longitude' => 13.03,
                'hourly' => [
                    'time' => ['2026-10-25T12:00'],
                    'precipitation' => [0.2],
                    'precipitation_probability' => [5],
                    'temperature_2m' => [25.0],
                ],
            ], 200),
        ]);

        $this->artisan('early-warning:fetch-forecasts', ['--sync' => true])->assertExitCode(0);
        $this->assertSame(1, Forecast::query()->count());
    }
}
