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
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Stored exercise forecasts (source ncmcd-demo) show in /warnings for their own org only. */
class StoredForecastWarningsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'early-warning', 'version' => '0.4.0', 'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(), 'meta' => [],
        ])->save();
        Carbon::setTestNow(Carbon::parse('2026-11-10 04:00:00', 'UTC'));
        Basin::query()->create(['basin_code' => 'TRP-HIRA', 'name' => 'Wadi Al-Hira', 'region' => 'tripoli']);
    }

    private function orgUser(): User
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $org->id, 'plugin_key' => 'early-warning', 'enabled' => true, 'enabled_at' => now(),
        ])->save();
        Subscription::query()->create(['organization_id' => $org->id, 'basin_code' => 'TRP-HIRA']);
        app(PluginManager::class)->forgetOrganization($org->id);
        app(PluginManager::class)->forgetInstalled();

        return $user;
    }

    private function precipNow(User $user): ?float
    {
        $basin = collect($this->actingAs($user)->getJson('/x/early-warning/warnings?hours=6')->assertOk()->json('basins'))
            ->firstWhere('basin_code', 'TRP-HIRA');

        return $basin['timeline'][0]['precip_mm'] ?? null;
    }

    public function test_stored_demo_forecast_shows_for_owner_org_only(): void
    {
        $owner = $this->orgUser();
        $other = $this->orgUser();
        Forecast::query()->create([
            'basin_code' => 'TRP-HIRA', 'source' => 'ncmcd-demo', 'fetched_at' => now(), 'forecast_at' => now(),
            'precipitation_mm' => 7.5, 'props' => ['organization_id' => $owner->organization_id],
        ]);

        $this->assertSame(7.5, $this->precipNow($owner));
        $this->assertNull($this->precipNow($other));
    }

    public function test_open_meteo_wins_over_stored_for_same_hour(): void
    {
        $owner = $this->orgUser();
        Forecast::query()->create([
            'basin_code' => 'TRP-HIRA', 'source' => 'ncmcd-demo', 'fetched_at' => now(), 'forecast_at' => now(),
            'precipitation_mm' => 7.5, 'props' => ['organization_id' => $owner->organization_id],
        ]);
        Forecast::query()->create([
            'basin_code' => 'TRP-HIRA', 'source' => 'open-meteo', 'fetched_at' => now(), 'forecast_at' => now(),
            'precipitation_mm' => 1.25,
        ]);

        $this->assertSame(1.25, $this->precipNow($owner));
    }

    public function test_unknown_source_is_ignored(): void
    {
        $owner = $this->orgUser();
        Forecast::query()->create([
            'basin_code' => 'TRP-HIRA', 'source' => 'other-feed', 'fetched_at' => now(), 'forecast_at' => now(),
            'precipitation_mm' => 9, 'props' => ['organization_id' => $owner->organization_id],
        ]);

        $this->assertNull($this->precipNow($owner));
    }
}
