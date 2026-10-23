<?php

namespace Lumina\EarlyWarning\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #59: ew_basins migration + early-warning:link-basins (default test DB, SQLite). */
class LinkBasinsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        $this->fixture = __DIR__.'/fixtures/tripoli_basins_fixture.geojson';
    }

    public function test_ew_basins_is_global_and_stores_geometry(): void
    {
        $this->assertTrue(Schema::hasTable('ew_basins'));
        $this->assertFalse(Schema::hasColumn('ew_basins', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('ew_basins', 'geom'));
        $this->assertTrue(Schema::hasColumn('ew_basins', 'props'));
        $this->assertFalse(Schema::hasColumn('ew_basins', 'attributes'));
    }

    public function test_links_basins_and_is_idempotent(): void
    {
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture])->assertExitCode(0);
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture])->assertExitCode(0);

        $this->assertSame(2, Basin::count());
        $b = Basin::where('basin_code', 'TRP-MAJENIN')->first();
        $this->assertSame('Wadi Al-Majenin', $b->name);
        $this->assertEquals(12.5, (float) $b->area_km2);
        $this->assertNull($b->layer_id);
        $this->assertNull($b->feature_id);
        $this->assertEquals(1.0, $b->props['stream_threshold_km2']);
        $geom = json_decode(DB::table('ew_basins')->where('basin_code', 'TRP-MAJENIN')->value('geom'), true);
        $this->assertSame('Polygon', $geom['type']);
    }

    public function test_links_to_existing_layer_falls_back_to_geojson_id(): void
    {
        $layerId = $this->makeLayer();
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture, '--layer' => $layerId])->assertExitCode(0);
        $this->assertSame(2, Basin::where('layer_id', $layerId)->count());
        $this->assertSame('1', Basin::where('basin_code', 'TRP-MAJENIN')->value('feature_id'));
    }

    public function test_feature_id_resolved_from_layer_import_table(): void
    {
        Schema::create('import_tripoli_basins', function ($t) {
            $t->increments('gid');
            $t->string('basin_code');
        });
        DB::table('import_tripoli_basins')->insert([['gid' => 41, 'basin_code' => 'TRP-MAJENIN'], ['gid' => 42, 'basin_code' => 'TRP-RABIE']]);
        $layerId = $this->makeLayer();

        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture, '--layer' => $layerId])->assertExitCode(0);

        $this->assertSame('41', Basin::where('basin_code', 'TRP-MAJENIN')->value('feature_id'));
        $this->assertSame('42', Basin::where('basin_code', 'TRP-RABIE')->value('feature_id'));
    }

    public function test_rejects_layer_not_owned_by_plugin(): void
    {
        $layerId = $this->makeLayer(['plugin' => 'something-else']);
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture, '--layer' => $layerId])->assertExitCode(1);
        $this->assertSame(0, Basin::count());
    }

    public function test_fails_for_missing_layer_or_file(): void
    {
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture, '--layer' => 999999])->assertExitCode(1);
        $this->artisan('early-warning:link-basins', ['geojson' => '/nope.geojson'])->assertExitCode(1);
        $this->assertSame(0, Basin::count());
    }

    public function test_missing_basin_code_fails_closed(): void
    {
        $data = json_decode(file_get_contents($this->fixture), true);
        unset($data['features'][1]['properties']['basin_code']);
        $path = $this->tmpGeojson($data);

        $this->artisan('early-warning:link-basins', ['geojson' => $path])
            ->expectsOutputToContain('Feature #1: missing basin_code')
            ->assertExitCode(1);
        $this->assertSame(0, Basin::count());
    }

    public function test_per_feature_schema_check_rejects_bad_geometry_and_types(): void
    {
        $data = json_decode(file_get_contents($this->fixture), true);
        $data['features'][0]['geometry'] = ['type' => 'Point', 'coordinates' => [13.1, 32.8]];
        $data['features'][1]['properties']['area_km2'] = 'big';
        $path = $this->tmpGeojson($data);

        $this->artisan('early-warning:link-basins', ['geojson' => $path])
            ->expectsOutputToContain('Feature #0: geometry must be a non-empty Polygon or MultiPolygon')
            ->expectsOutputToContain('Feature #1: area_km2 must be numeric')
            ->assertExitCode(1);
        $this->assertSame(0, Basin::count());
    }

    public function test_migration_down_drops_table(): void
    {
        $this->artisan('migrate:rollback', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('ew_basins'));
    }

    private function tmpGeojson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ew').'.geojson';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    private function makeLayer(array $metadata = ['plugin' => 'early-warning']): int
    {
        $user = \App\Models\User::factory()->create();
        $orgId = DB::table('organizations')->insertGetId(['name' => 'Org', 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('layers')->insertGetId([
            'user_id' => $user->id, 'organization_id' => $orgId, 'name' => 'Tripoli basins',
            'table_name' => 'import_tripoli_basins', 'geometry_type' => 'Polygon',
            'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
