<?php

namespace Tests\Feature\Plugins\EarlyWarning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #59: ew_basins migration + early-warning:link-basins. */
class LinkBasinsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        // TODO(#54): drop this once the plugin registry + composer path repo exist.
        spl_autoload_register(function ($class) {
            $prefix = 'Lumina\\EarlyWarning\\';
            if (str_starts_with($class, $prefix)) {
                $f = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($f)) {
                    require $f;
                }
            }
        });
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        $this->fixture = __DIR__.'/fixtures/tripoli_basins_fixture.geojson';
    }

    public function test_ew_basins_is_global_without_organization_id(): void
    {
        $this->assertTrue(Schema::hasTable('ew_basins'));
        $this->assertFalse(Schema::hasColumn('ew_basins', 'organization_id'));
    }

    public function test_links_basins_and_is_idempotent(): void
    {
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture])->assertExitCode(0);
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture])->assertExitCode(0);

        $this->assertSame(2, Basin::count());
        $b = Basin::where('basin_code', 'TRP-MAJENIN')->first();
        $this->assertSame('Wadi Al-Majenin', $b->name);
        $this->assertEquals(12.5, (float) $b->area_km2);
        $this->assertSame('1', $b->feature_id);
        $this->assertEquals(1.0, $b->attributes['stream_threshold_km2']);
    }

    public function test_links_to_existing_layer(): void
    {
        $layerId = $this->makeLayer();
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture, '--layer' => $layerId])->assertExitCode(0);
        $this->assertSame(2, Basin::where('layer_id', $layerId)->count());
    }

    public function test_fails_for_missing_layer_or_file(): void
    {
        $this->artisan('early-warning:link-basins', ['geojson' => $this->fixture, '--layer' => 999999])->assertExitCode(1);
        $this->artisan('early-warning:link-basins', ['geojson' => '/nope.geojson'])->assertExitCode(1);
        $this->assertSame(0, Basin::count());
    }

    private function makeLayer(): int
    {
        $user = \App\Models\User::factory()->create();
        $orgId = DB::table('organizations')->insertGetId(['name' => 'Org', 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('layers')->insertGetId([
            'user_id' => $user->id, 'organization_id' => $orgId, 'name' => 'Tripoli basins',
            'table_name' => 'import_tripoli_basins', 'geometry_type' => 'Polygon',
            'metadata' => json_encode(['plugin' => 'early-warning']), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
