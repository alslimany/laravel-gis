<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Exposure\BasinBbox;
use Lumina\EarlyWarning\Ingestion\OverpassClient;
use Lumina\EarlyWarning\Jobs\ComputeExposureJob;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\ExposureSummary;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Phase 3: OSM exposure summaries via Overpass. */
class ComputeExposureTest extends TestCase
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
            'version' => '0.8.0',
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

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
            'outlet_lon' => 13.03,
            'outlet_lat' => 32.83,
            'area_km2' => 12.5,
        ]);
    }

    public function test_exposure_table_is_global(): void
    {
        $this->assertTrue(Schema::hasTable('ew_exposure_summaries'));
        $this->assertFalse(Schema::hasColumn('ew_exposure_summaries', 'organization_id'));
    }

    public function test_basin_bbox_covers_outlet(): void
    {
        $basin = Basin::query()->first();
        $box = app(BasinBbox::class)->for($basin);

        $this->assertTrue($box['south'] < 32.83 && $box['north'] > 32.83);
        $this->assertTrue($box['west'] < 13.03 && $box['east'] > 13.03);
    }

    public function test_overpass_client_parses_count_and_road_geom(): void
    {
        $this->fakeOverpass(13, [
            ['lat' => 32.83, 'lon' => 13.03],
            ['lat' => 32.84, 'lon' => 13.03],
        ]);

        $stats = app(OverpassClient::class)->exposureInBbox([
            'south' => 32.8,
            'west' => 13.0,
            'north' => 32.9,
            'east' => 13.1,
        ]);

        $this->assertSame(13, $stats['building_count']);
        $this->assertSame(1, $stats['road_segment_count']);
        $this->assertGreaterThan(0.5, $stats['road_km']);
        $this->assertLessThan(2.0, $stats['road_km']);
    }

    public function test_compute_exposure_job_upserts_summary(): void
    {
        $this->fakeOverpass(40, [
            ['lat' => 32.83, 'lon' => 13.03],
            ['lat' => 32.83, 'lon' => 13.04],
        ]);

        (new ComputeExposureJob)->handle(
            app(OverpassClient::class),
            app(BasinBbox::class),
            app(PluginManager::class),
        );

        $row = ExposureSummary::query()->where('basin_code', 'TRP-HIRA')->first();
        $this->assertNotNull($row);
        $this->assertSame(40, $row->building_count);
        $this->assertSame(1, $row->road_segment_count);
        $this->assertGreaterThan(0, $row->road_km);
        $this->assertSame('overpass', $row->source);
    }

    public function test_warnings_api_includes_exposure_fields(): void
    {
        ExposureSummary::query()->create([
            'basin_code' => 'TRP-HIRA',
            'building_count' => 100,
            'road_segment_count' => 20,
            'road_km' => 12.5,
            'source' => 'overpass',
            'fetched_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->getJson('/x/early-warning/warnings?hours=6')
            ->assertOk()
            ->assertJsonPath('basins.0.basin_code', 'TRP-HIRA')
            ->assertJsonPath('basins.0.buildings', 100)
            ->assertJsonPath('basins.0.road_km', 12.5);
    }

    public function test_artisan_sync_command(): void
    {
        $this->fakeOverpass(0, []);

        $this->artisan('early-warning:compute-exposure', ['--sync' => true, '--basin' => 'TRP-HIRA'])
            ->assertExitCode(0);
    }

    /**
     * @param  list<array{lat: float, lon: float}>  $geometry
     */
    protected function fakeOverpass(int $buildingTotal, array $geometry): void
    {
        Http::fake(function ($request) use ($buildingTotal, $geometry) {
            $payload = urldecode((string) $request->body());
            // Building query uses `out count`; roads use `out geom`.
            if (str_contains($payload, '["building"]') || str_contains($payload, 'out count')) {
                return Http::response([
                    'elements' => [
                        ['type' => 'count', 'tags' => ['total' => (string) $buildingTotal]],
                    ],
                ], 200);
            }

            $elements = $geometry === [] ? [] : [
                ['type' => 'way', 'geometry' => $geometry],
            ];

            return Http::response(['elements' => $elements], 200);
        });
    }
}
