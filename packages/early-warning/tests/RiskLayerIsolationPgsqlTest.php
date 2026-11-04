<?php

namespace Lumina\EarlyWarning\Tests;

use Illuminate\Support\Facades\DB;
use Lumina\EarlyWarning\Publishing\RiskLayerPublisher;
use Tests\TestCase;

/**
 * Cross-tenant check on real PostGIS: each org's basin-risk view only holds that org's basins.
 * Same guard as PostgisGeometryTest: needs EW_PGSQL_TEST_URL pointing at a *_test database.
 * Everything lives in a throwaway schema that is dropped afterwards.
 */
class RiskLayerIsolationPgsqlTest extends TestCase
{
    private string $schema = '';

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('EW_PGSQL_TEST_URL') ?: null;
        if (! $url) {
            if (getenv('EW_REQUIRE_PGSQL') === '1' || (getenv('CI') !== false && getenv('CI') !== '')) {
                $this->fail('EW_PGSQL_TEST_URL is not set but EW_REQUIRE_PGSQL=1 or CI is set.');
            }
            $this->markTestSkipped('EW_PGSQL_TEST_URL not set; PostGIS isolation test skipped.');
        }
        $dbName = PostgisGeometryTest::databaseName($url);
        if ($dbName === null || ! str_ends_with($dbName, '_test')) {
            $this->fail('Refusing to run: EW_PGSQL_TEST_URL database must end in "_test".');
        }

        $this->schema = 'ew_iso_'.bin2hex(random_bytes(4));
        config(['database.connections.ew_iso' => [
            'driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '',
            'search_path' => $this->schema.',public', 'sslmode' => 'disable',
        ]]);
        DB::purge('ew_iso');
        DB::connection('ew_iso')->statement("CREATE SCHEMA {$this->schema}");
        DB::connection('ew_iso')->statement("SET search_path TO {$this->schema}, public");
    }

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            DB::connection('ew_iso')->statement("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
            DB::disconnect('ew_iso');
        }
        parent::tearDown();
    }

    public function test_each_org_view_contains_only_its_own_basin_codes(): void
    {
        $db = DB::connection('ew_iso');
        $db->statement('CREATE TABLE ew_basins (basin_code text primary key, name text, name_ar text, geom geometry(MultiPolygon,4326))');
        $db->statement('CREATE TABLE ew_risk_assessments (id bigserial primary key, organization_id bigint, basin_code text,
            forecast_at timestamptz, valid_from timestamptz, valid_until timestamptz, risk_level text, score numeric)');

        $poly = "ST_Multi(ST_GeomFromText('POLYGON((13 32,13.1 32,13.1 32.1,13 32.1,13 32))',4326))";
        foreach (['A-1' => 1, 'A-2' => 1, 'B-1' => 2, 'B-2' => 2] as $code => $org) {
            $db->statement("INSERT INTO ew_basins (basin_code, name, geom) VALUES ('{$code}', '{$code}', {$poly})");
            $db->statement("INSERT INTO ew_risk_assessments (organization_id, basin_code, forecast_at, valid_from, valid_until, risk_level, score)
                VALUES ({$org}, '{$code}', now(), now() - interval '1 hour', now() + interval '1 hour', 'red', 80)");
        }

        $publisher = $this->app->make(RiskLayerPublisher::class);
        foreach ([1, 2] as $org) {
            $view = RiskLayerPublisher::tableFor($org);
            $db->statement("CREATE VIEW \"{$view}\" AS ".$publisher->sqlForOrganization($org));
        }

        $a = $db->table(RiskLayerPublisher::tableFor(1))->pluck('basin_code')->sort()->values()->all();
        $b = $db->table(RiskLayerPublisher::tableFor(2))->pluck('basin_code')->sort()->values()->all();

        $this->assertSame(['A-1', 'A-2'], $a);
        $this->assertSame(['B-1', 'B-2'], $b);
        $this->assertSame([], array_intersect($a, ['B-1', 'B-2']), "org A's view holds org B basins");
    }
}
