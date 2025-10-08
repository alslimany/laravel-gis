<?php

namespace Tests\Unit;

use App\Exceptions\GeoServerException;
use App\Services\GeoServerService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GeoServerServiceTest extends TestCase
{
    protected GeoServerService $geoserver;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock configuration
        Config::set('geoserver.url', 'http://test-geoserver:8080/geoserver');
        Config::set('geoserver.admin_user', 'admin');
        Config::set('geoserver.admin_password', 'geoserver');
        Config::set('geoserver.workspace', 'test_workspace');
        Config::set('geoserver.datastore', 'test_datastore');
        Config::set('geoserver.timeout', 30);
        Config::set('geoserver.retry_times', 3);
        Config::set('geoserver.retry_delay', 1000);
        Config::set('geoserver.postgis', [
            'host' => 'postgis',
            'port' => '5432',
            'database' => 'test_db',
            'schema' => 'public',
            'user' => 'postgres',
            'password' => 'secret',
        ]);

        $this->geoserver = new GeoServerService;
    }

    public function test_geoserver_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(GeoServerService::class, $this->geoserver);
    }

    public function test_can_generate_default_point_style(): void
    {
        $sld = $this->geoserver->getDefaultPointStyle('test_style', [
            'color' => '#FF0000',
            'size' => 10,
        ]);

        $this->assertStringContainsString('test_style', $sld);
        $this->assertStringContainsString('#FF0000', $sld);
        $this->assertStringContainsString('<Size>10</Size>', $sld);
        $this->assertStringContainsString('PointSymbolizer', $sld);
    }

    public function test_can_generate_default_polygon_style(): void
    {
        $sld = $this->geoserver->getDefaultPolygonStyle('test_polygon_style', [
            'fillColor' => '#00FF00',
            'strokeColor' => '#000000',
            'strokeWidth' => 2,
            'fillOpacity' => 0.7,
        ]);

        $this->assertStringContainsString('test_polygon_style', $sld);
        $this->assertStringContainsString('#00FF00', $sld);
        $this->assertStringContainsString('#000000', $sld);
        $this->assertStringContainsString('<CssParameter name="stroke-width">2</CssParameter>', $sld);
        $this->assertStringContainsString('<CssParameter name="fill-opacity">0.7</CssParameter>', $sld);
        $this->assertStringContainsString('PolygonSymbolizer', $sld);
    }

    public function test_geoserver_exception_methods(): void
    {
        $workspaceException = GeoServerException::workspaceCreationFailed('test_workspace');
        $this->assertStringContainsString('test_workspace', $workspaceException->getMessage());

        $datastoreException = GeoServerException::datastoreCreationFailed('test_datastore');
        $this->assertStringContainsString('test_datastore', $datastoreException->getMessage());

        $layerException = GeoServerException::layerPublishFailed('test_layer');
        $this->assertStringContainsString('test_layer', $layerException->getMessage());

        $deleteException = GeoServerException::layerDeletionFailed('test_layer');
        $this->assertStringContainsString('test_layer', $deleteException->getMessage());

        $styleException = GeoServerException::styleUpdateFailed('test_style');
        $this->assertStringContainsString('test_style', $styleException->getMessage());

        $connectionException = GeoServerException::connectionFailed();
        $this->assertStringContainsString('connect', $connectionException->getMessage());
    }
}
