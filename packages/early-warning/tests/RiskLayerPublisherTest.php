<?php

namespace Lumina\EarlyWarning\Tests;

use App\Contracts\LayerPublisher;
use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\CoreLayerPublisher;
use App\Services\GeoServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Lumina\EarlyWarning\Publishing\RiskLayerPublisher;
use Mockery;
use Tests\TestCase;

/** #61: Layer row + trusted SQL View payload for GeoServer. */
class RiskLayerPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

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
            'version' => '0.3.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $this->org = Organization::factory()->create(['name' => 'Pilot Org']);
        User::factory()->create(['organization_id' => $this->org->id]);
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $this->org->id,
            'plugin_key' => 'early-warning',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();
    }

    public function test_sql_embeds_integer_org_id_not_user_input(): void
    {
        $publisher = app(RiskLayerPublisher::class);
        $sql = $publisher->sqlForOrganization((int) $this->org->id);

        $this->assertStringContainsString('organization_id = '.(int) $this->org->id, $sql);
        $this->assertStringNotContainsString(';', $sql);
        $this->assertStringContainsString('ew_risk_assessments', $sql);
        $this->assertStringContainsString('ew_basins', $sql);
    }

    public function test_publish_upserts_layer_without_geoserver(): void
    {
        Basin::query()->create([
            'basin_code' => 'TRP-MAJENIN',
            'name' => 'Majenin',
            'region' => 'tripoli',
        ]);
        RiskAssessment::query()->create([
            'organization_id' => $this->org->id,
            'basin_code' => 'TRP-MAJENIN',
            'forecast_at' => now()->startOfHour(),
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addHour(),
            'risk_level' => 'yellow',
            'score' => 32.5,
            'inputs' => ['advisory' => true],
        ]);

        $result = app(RiskLayerPublisher::class)->publish($this->org, syncGeoServer: false);

        $this->assertFalse($result['published']);
        $this->assertSame('early-warning', $result['layer']->metadata['plugin']);
        $this->assertSame(RiskLayerPublisher::GEOSERVER_LAYER, $result['layer']->table_name);
        $this->assertSame('MultiPolygon', $result['layer']->geometry_type);
    }

    public function test_publish_calls_geoserver_sql_view_when_enabled(): void
    {
        $geo = Mockery::mock(GeoServerService::class);
        $geo->shouldReceive('workspaceExists')->once()->andReturn(true);
        $geo->shouldReceive('datastoreExists')->once()->andReturn(true);
        $geo->shouldReceive('publishSqlView')->once()->withArgs(function ($ws, $ds, $name, $sql) {
            return $name === RiskLayerPublisher::GEOSERVER_LAYER
                && str_contains($sql, 'organization_id = '.(int) $this->org->id);
        })->andReturn(true);
        $geo->shouldReceive('createOrUpdateStyle')->once()->andReturn(true);
        $geo->shouldReceive('applyStyleToLayer')->once()->andReturn(true);
        $this->app->instance(GeoServerService::class, $geo);
        $this->app->instance(LayerPublisher::class, new CoreLayerPublisher($geo));

        $result = app(RiskLayerPublisher::class)->publish($this->org, syncGeoServer: true);

        $this->assertTrue($result['published']);
        $this->assertTrue($result['layer']->published);
        $this->assertSame(RiskLayerPublisher::GEOSERVER_LAYER, $result['layer']->geoserver_layer_name);
    }

    public function test_artisan_publish_no_geoserver(): void
    {
        $this->artisan('early-warning:publish-risk-layer', [
            '--organization' => $this->org->id,
            '--no-geoserver' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('layers', [
            'organization_id' => $this->org->id,
            'table_name' => RiskLayerPublisher::GEOSERVER_LAYER,
        ]);
    }
}
