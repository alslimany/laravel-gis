<?php

namespace Tests\Feature;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use App\Services\CoreLayerPublisher;
use App\Services\FeatureTableProvisioner;
use App\Services\GeoServerService;
use App\Services\LayerExtent;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Regression: SQL-view layers drew nothing in the map builder because the
 * builder reads vector tiles from `layers.table_name` in Postgres, while the
 * data only existed in GeoServer (an empty placeholder table shadowed it).
 */
class SqlViewLayerPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function organization(): Organization
    {
        $org = Organization::factory()->create();
        User::factory()->create(['organization_id' => $org->id]);

        return $org->fresh();
    }

    protected function mockGeoServer(?callable $expect = null): GeoServerService
    {
        $gs = Mockery::mock(GeoServerService::class);
        $gs->shouldReceive('workspaceExists')->andReturn(true);
        $gs->shouldReceive('datastoreExists')->andReturn(true);
        if ($expect) {
            $expect($gs);
        }
        $this->app->instance(GeoServerService::class, $gs);
        $this->app->forgetInstance(LayerPublisher::class);

        return $gs;
    }

    public function test_default_style_matches_geometry_type(): void
    {
        $this->assertSame('point', CoreLayerPublisher::defaultStyleFor('Point'));
        $this->assertSame('point', CoreLayerPublisher::defaultStyleFor('MultiPoint'));
        $this->assertSame('line', CoreLayerPublisher::defaultStyleFor('MultiLineString'));
        $this->assertSame('polygon', CoreLayerPublisher::defaultStyleFor('MultiPolygon'));
        $this->assertSame('generic', CoreLayerPublisher::defaultStyleFor(null));
    }

    public function test_layer_without_sld_gets_geometry_default_style_and_a_bbox(): void
    {
        $captured = null;
        $this->mockGeoServer(function ($gs) use (&$captured) {
            $gs->shouldReceive('publishSqlView')->once()
                ->withArgs(function ($ws, $ds, $name, $sql, $options) use (&$captured) {
                    $captured = $options;

                    return $name === 'dm_org_assets';
                })->andReturn(true);
            $gs->shouldReceive('applyStyleToLayer')->once()
                ->with(Mockery::any(), 'dm_org_assets', 'point')->andReturn(true);
            $gs->shouldNotReceive('createOrUpdateStyle');
        });

        $result = app(LayerPublisher::class)->upsertSqlViewLayer(
            $this->organization(),
            ['table_name' => 'dm_org_assets_9', 'name' => 'Assets', 'geometry_type' => 'Point'],
            'SELECT 1 AS id, NULL AS geom',
            ['layerName' => 'dm_org_assets', 'geometryType' => 'Point'],
        );

        $this->assertTrue($result['published']);
        $this->assertIsArray($captured['bbox'] ?? null);
        $this->assertCount(4, $captured['bbox']);
        // sqlite has no ST_Extent: the world fallback keeps GeoServer valid.
        $this->assertSame(CoreLayerPublisher::FALLBACK_BBOX, $captured['bbox']);
    }

    public function test_layer_with_custom_sld_keeps_its_style(): void
    {
        $this->mockGeoServer(function ($gs) {
            $gs->shouldReceive('publishSqlView')->once()->andReturn(true);
            $gs->shouldReceive('createOrUpdateStyle')->once()->andReturn(true);
            $gs->shouldReceive('applyStyleToLayer')->once()
                ->with(Mockery::any(), 'ew_basin_risk', 'ew_basin_risk')->andReturn(true);
        });

        app(LayerPublisher::class)->upsertSqlViewLayer(
            $this->organization(),
            ['table_name' => 'ew_basin_risk', 'name' => 'Risk', 'geometry_type' => 'MultiPolygon'],
            'SELECT 1 AS id, NULL AS geom',
            ['layerName' => 'ew_basin_risk', 'styleName' => 'ew_basin_risk', 'styleSld' => '<sld/>'],
        );
    }

    public function test_failed_publish_does_not_apply_a_style(): void
    {
        $this->mockGeoServer(function ($gs) {
            $gs->shouldReceive('publishSqlView')->once()->andReturn(false);
            $gs->shouldNotReceive('applyStyleToLayer');
        });

        $result = app(LayerPublisher::class)->upsertSqlViewLayer(
            $this->organization(),
            ['table_name' => 'ew_basin_risk', 'name' => 'Risk'],
            'SELECT 1 AS id, NULL AS geom',
            ['layerName' => 'ew_basin_risk'],
        );

        $this->assertFalse($result['published']);
    }

    public function test_geoserver_featuretype_payload_carries_the_bbox(): void
    {
        $history = [];
        $gs = $this->geoServerWithResponses([
            new Response(200, [], '{}'),            // layerExists → yes
            new Response(200, [], ''),              // PUT featuretype
        ], $history);

        $gs->publishSqlView('org_3', 'postgis', 'dm_org_assets', 'SELECT 1', [
            'geometryType' => 'Point',
            'bbox' => [13.0, 32.0, 13.5, 32.9],
        ]);

        $put = $history[1]['request'];
        $this->assertSame('PUT', $put->getMethod());
        $this->assertStringNotContainsString('recalculate', (string) $put->getUri());
        $body = json_decode((string) $put->getBody(), true);
        $this->assertEquals(
            ['minx' => 13.0, 'miny' => 32.0, 'maxx' => 13.5, 'maxy' => 32.9, 'crs' => 'EPSG:4326'],
            $body['featureType']['nativeBoundingBox'],
        );
        $this->assertEquals($body['featureType']['nativeBoundingBox'], $body['featureType']['latLonBoundingBox']);
    }

    public function test_geoserver_update_without_bbox_asks_geoserver_to_recalculate(): void
    {
        $history = [];
        $gs = $this->geoServerWithResponses([
            new Response(200, [], '{}'),
            new Response(200, [], ''),
        ], $history);

        $gs->publishSqlView('org_3', 'postgis', 'ew_basin_risk', 'SELECT 1');

        $this->assertStringContainsString(
            'recalculate=nativebbox,latlonbbox',
            urldecode((string) $history[1]['request']->getUri()),
        );
    }

    public function test_provisioner_never_shadows_a_backing_view(): void
    {
        DB::statement('CREATE VIEW ew_view_layer AS SELECT 1 AS id, NULL AS geom');
        $org = $this->organization();
        $layer = Layer::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $org->members()->value('id'),
            'table_name' => 'ew_view_layer',
            'geometry_type' => 'MultiPolygon',
        ]);

        $this->assertFalse(app(FeatureTableProvisioner::class)->ensureFor($layer));
        $this->assertFalse(Schema::hasTable('ew_view_layer'));
        $this->assertTrue(Schema::hasView('ew_view_layer'));
    }

    public function test_layer_extent_reads_a_backing_view(): void
    {
        DB::statement("CREATE VIEW ew_view_extent AS SELECT 1 AS id, 'POINT(13.1 32.8)' AS geom UNION ALL SELECT 2, 'POINT(13.3 32.9)'");
        $org = $this->organization();
        $layer = Layer::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $org->members()->value('id'),
            'table_name' => 'ew_view_extent',
            'geometry_type' => 'Point',
            'feature_count' => 2,
        ]);

        $extent = app(LayerExtent::class)->for($layer);

        $this->assertNotNull($extent);
        $this->assertEqualsWithDelta([13.1, 32.8, 13.3, 32.9], $extent, 1e-9);
    }

    public function test_placeholder_with_extra_column_is_refused(): void
    {
        Schema::create('ew_placeholder_extra', function ($t) {
            $t->increments('id');
            $t->text('geom')->nullable();
            $t->string('note')->nullable();
        });
        $layer = $this->sqlViewLayerRow('ew_placeholder_extra');

        try {
            app(CoreLayerPublisher::class)->assertReplaceablePlaceholder($layer, 'ew_placeholder_extra');
            $this->fail('Expected the table to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not an id+geom placeholder', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('ew_placeholder_extra'));
    }

    public function test_placeholder_is_refused_when_layer_is_not_flagged_sql_view(): void
    {
        Schema::create('ew_placeholder_plain', function ($t) {
            $t->increments('id');
            $t->text('geom')->nullable();
        });
        $layer = $this->sqlViewLayerRow('ew_placeholder_plain', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not flagged sql_view');
        app(CoreLayerPublisher::class)->assertReplaceablePlaceholder($layer, 'ew_placeholder_plain');
    }

    public function test_placeholder_with_rows_is_refused(): void
    {
        Schema::create('ew_placeholder_rows', function ($t) {
            $t->increments('id');
            $t->text('geom')->nullable();
        });
        DB::table('ew_placeholder_rows')->insert(['geom' => null]);
        $layer = $this->sqlViewLayerRow('ew_placeholder_rows');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('holds data');
        app(CoreLayerPublisher::class)->assertReplaceablePlaceholder($layer, 'ew_placeholder_rows');
    }

    public function test_empty_id_geom_placeholder_on_sql_view_layer_is_replaceable(): void
    {
        Schema::create('ew_placeholder_ok', function ($t) {
            $t->increments('id');
            $t->text('geom')->nullable();
        });
        $layer = $this->sqlViewLayerRow('ew_placeholder_ok');

        app(CoreLayerPublisher::class)->assertReplaceablePlaceholder($layer, 'ew_placeholder_ok');
        $this->assertTrue(true);
    }

    public function test_sql_with_a_semicolon_after_trimming_is_rejected(): void
    {
        $org = $this->organization();
        $this->mockGeoServer(fn ($gs) => $gs->shouldNotReceive('publishSqlView'));

        try {
            app(LayerPublisher::class)->upsertSqlViewLayer(
                $org,
                ['table_name' => 'ew_bad_sql', 'name' => 'Bad'],
                'SELECT 1 AS id, NULL AS geom; DROP TABLE users',
                ['layerName' => 'ew_bad_sql'],
            );
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("';'", $e->getMessage());
        }

        $this->assertDatabaseMissing('layers', ['table_name' => 'ew_bad_sql']);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame('SELECT 1', CoreLayerPublisher::assertSingleStatement("  SELECT 1 ;\n"));
    }

    public function test_publish_result_reports_backing_view_and_flags_sql_view(): void
    {
        $org = $this->organization();
        $this->mockGeoServer();

        $result = app(LayerPublisher::class)->upsertSqlViewLayer(
            $org,
            ['table_name' => 'ew_result_flag', 'name' => 'Flag', 'metadata' => ['plugin' => 'early-warning']],
            'SELECT 1 AS id, NULL AS geom',
            null,
        );

        $this->assertArrayHasKey('backing_view', $result);
        // Views are only created on Postgres; the sqlite test DB reports false.
        $this->assertSame(DB::getDriverName() === 'pgsql', $result['backing_view']);
        $this->assertTrue($result['layer']->metadata['sql_view']);
        $this->assertSame('early-warning', $result['layer']->metadata['plugin']);
        $this->assertTrue($result['layer']->isSqlView());
    }

    protected function sqlViewLayerRow(string $table, array $metadata = ['sql_view' => true]): Layer
    {
        $org = $this->organization();

        return Layer::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $org->members()->value('id'),
            'table_name' => $table,
            'geometry_type' => 'Point',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  list<Response>  $responses
     */
    protected function geoServerWithResponses(array $responses, array &$history): GeoServerService
    {
        Config::set('geoserver.url', 'http://test-geoserver:8080/geoserver');
        Config::set('geoserver.admin_user', 'admin');
        Config::set('geoserver.admin_password', 'test');

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $gs = new GeoServerService;
        $property = new \ReflectionProperty(GeoServerService::class, 'client');
        $property->setValue($gs, new Client(['handler' => $stack, 'http_errors' => false]));

        return $gs;
    }
}
