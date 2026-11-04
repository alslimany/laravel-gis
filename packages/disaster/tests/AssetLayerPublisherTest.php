<?php

namespace Lumina\Disaster\Tests;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Services\CoreLayerPublisher;
use App\Services\GeoServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Publishing\AssetLayerPublisher;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Mockery;
use Tests\TestCase;

/**
 * Runs AssetLayerPublisher through the REAL CoreLayerPublisher (GeoServer mocked), so a
 * mismatched argument order against LayerPublisher::upsertSqlViewLayer fails here.
 */
class AssetLayerPublisherTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    private function bindCorePublisher(GeoServerService $geoserver): void
    {
        $this->app->instance(GeoServerService::class, $geoserver);
        $this->app->bind(LayerPublisher::class, fn () => new CoreLayerPublisher($geoserver));
    }

    public function test_publishes_sql_view_to_geoserver_via_core_publisher(): void
    {
        $this->installDisasterPlugin();
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id, 'facility_id' => 'T-MF-1', 'name' => 'Test MF',
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        $geoserver = Mockery::mock(GeoServerService::class);
        $geoserver->shouldReceive('workspaceExists')->andReturn(true);
        $geoserver->shouldReceive('datastoreExists')->andReturn(true);
        $geoserver->shouldReceive('publishSqlView')->once()->withArgs(function ($ws, $ds, $layerName, $sql, $opts) {
            return $layerName === AssetLayerPublisher::GEOSERVER_LAYER
                && str_contains($sql, 'feature_key')
                && $opts['geometryType'] === 'Point'
                && $opts['keyColumn'] === 'feature_key';
        })->andReturn(true);
        // Core gives every published layer a style that matches its geometry.
        $geoserver->shouldReceive('applyStyleToLayer')->once()
            ->with(Mockery::any(), AssetLayerPublisher::GEOSERVER_LAYER, 'point')->andReturn(true);
        $this->bindCorePublisher($geoserver);

        $result = app(AssetLayerPublisher::class)->publish($this->disasterOrg, true, $this->disasterAdmin->id);

        $this->assertTrue($result['published']);
        $this->assertInstanceOf(Layer::class, $result['layer']);
        $this->assertSame('dm_org_assets_'.$this->disasterOrg->id, $result['layer']->table_name);
        $this->assertSame(1, $result['layer']->feature_count);
    }

    public function test_no_sync_upserts_layer_without_calling_geoserver(): void
    {
        $this->installDisasterPlugin();
        $geoserver = Mockery::mock(GeoServerService::class);
        $geoserver->shouldNotReceive('publishSqlView');
        $this->bindCorePublisher($geoserver);

        $this->artisan('disaster:publish-asset-layers', [
            'organization_id' => $this->disasterOrg->id, '--no-sync-geoserver' => true,
        ])->expectsOutputToContain('published: no')->assertExitCode(0);

        $this->assertSame(1, Layer::query()->where('table_name', 'dm_org_assets_'.$this->disasterOrg->id)->count());
    }

    public function test_polygon_critical_site_is_published_as_point_geometry(): void
    {
        $this->installDisasterPlugin();
        CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id, 'site_id' => 'T-CS-POLY', 'name' => 'Poly Site',
            'type' => CriticalSiteType::Dam->value,
            'geom' => GeometryWriter::polygonFromGeoJson([
                'type' => 'Polygon',
                'coordinates' => [[[13.1, 32.8], [13.2, 32.8], [13.2, 32.9], [13.1, 32.9], [13.1, 32.8]]],
            ]),
        ]);

        $captured = null;
        $geoserver = Mockery::mock(GeoServerService::class);
        $geoserver->shouldReceive('workspaceExists')->andReturn(true);
        $geoserver->shouldReceive('datastoreExists')->andReturn(true);
        $geoserver->shouldReceive('publishSqlView')->once()->withArgs(function ($ws, $ds, $layerName, $sql, $opts) use (&$captured) {
            $captured = $sql;

            return $opts['geometryType'] === 'Point';
        })->andReturn(true);
        // Core gives every published layer a style that matches its geometry.
        $geoserver->shouldReceive('applyStyleToLayer')->once()
            ->with(Mockery::any(), AssetLayerPublisher::GEOSERVER_LAYER, 'point')->andReturn(true);
        $this->bindCorePublisher($geoserver);

        $result = app(AssetLayerPublisher::class)->publish($this->disasterOrg, true, $this->disasterAdmin->id);

        $this->assertTrue($result['published']);
        $this->assertSame(1, $result['layer']->feature_count);
        $this->assertSame(2, substr_count($captured, 'ST_PointOnSurface(geom)::geometry(Point,4326) AS geom'));
        $this->assertStringContainsString('last_inspection_at DESC NULLS LAST', $captured);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select("SELECT feature_key, ST_GeometryType(geom) AS gt FROM ({$captured}) v");
            $this->assertCount(1, $rows);
            $this->assertSame('ST_Point', $rows[0]->gt);
        }
    }
}
