<?php

namespace Lumina\EarlyWarning\Tests;

use Illuminate\Support\Facades\DB;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/**
 * PostGIS geometry path for ew_basins. Skipped unless a disposable PostGIS
 * database is configured, e.g.:
 *   EW_PGSQL_TEST_URL=pgsql://postgres@127.0.0.1:54329/ew_test php vendor/bin/phpunit --testsuite Plugins
 * The table is dropped again after each test.
 */
class PostgisGeometryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('EW_PGSQL_TEST_URL') ?: null;
        if (! $url) {
            $this->markTestSkipped('EW_PGSQL_TEST_URL not set; PostGIS geometry test skipped.');
        }
        config(['database.connections.ew_pgsql' => ['driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable']]);
        config(['database.default' => 'ew_pgsql']);
        DB::purge('ew_pgsql');
        DB::statement('DROP TABLE IF EXISTS ew_basins');
        DB::statement('DROP TABLE IF EXISTS migrations');
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'ew_pgsql') {
            DB::statement('DROP TABLE IF EXISTS ew_basins');
            DB::statement('DROP TABLE IF EXISTS migrations');
            DB::disconnect('ew_pgsql');
        }
        parent::tearDown();
    }

    public function test_geometry_column_index_and_check(): void
    {
        $this->assertSame('MULTIPOLYGON', DB::scalar("SELECT type FROM geometry_columns WHERE f_table_name='ew_basins' AND f_geometry_column='geom'"));
        $this->assertSame(4326, (int) DB::scalar("SELECT srid FROM geometry_columns WHERE f_table_name='ew_basins'"));
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_indexes WHERE tablename='ew_basins' AND indexdef ILIKE '%USING gist%'"));

        $this->expectException(\Illuminate\Database\QueryException::class); // CHECK ST_IsValid
        DB::insert("INSERT INTO ew_basins (basin_code, name, region, geom) VALUES ('BAD','bad','t', ST_Multi(ST_GeomFromText('POLYGON((0 0,1 1,1 0,0 1,0 0))',4326)))");
    }

    public function test_link_basins_writes_valid_multipolygons_including_repaired_ones(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/fixtures/tripoli_basins_fixture.geojson'), true);
        // self-intersecting bow-tie: must be repaired by ST_MakeValid, not rejected
        $data['features'][1]['geometry'] = ['type' => 'Polygon', 'coordinates' => [[[13.3, 32.8], [13.4, 32.9], [13.4, 32.8], [13.3, 32.9], [13.3, 32.8]]]];
        $path = tempnam(sys_get_temp_dir(), 'ew').'.geojson';
        file_put_contents($path, json_encode($data));

        $this->artisan('early-warning:link-basins', ['geojson' => $path])->assertExitCode(0);

        $rows = DB::select('SELECT basin_code, GeometryType(geom) t, ST_SRID(geom) s, ST_IsValid(geom) v, ST_Area(geom) a FROM ew_basins ORDER BY basin_code');
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('MULTIPOLYGON', $r->t);
            $this->assertSame(4326, (int) $r->s);
            $this->assertTrue((bool) $r->v);
            $this->assertGreaterThan(0, (float) $r->a);
        }
    }

    public function test_down_drops_table(): void
    {
        $this->artisan('migrate:rollback', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        $this->assertNull(DB::scalar("SELECT to_regclass('public.ew_basins')"));
    }
}
