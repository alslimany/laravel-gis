<?php

namespace Lumina\EarlyWarning\Tests;

use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #58: Phase 1 tables + global vs org-scoped ownership (ADR D12). */
class Phase1SchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => EarlyWarningServiceProvider::migrationsPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    }

    public function test_phase1_tables_exist_with_expected_ownership(): void
    {
        foreach (['ew_basins', 'ew_forecasts', 'ew_observations', 'ew_exposure_summaries', 'ew_public_tokens', 'ew_citizen_reports', 'ew_subscriptions', 'ew_risk_assessments', 'ew_alert_rules', 'ew_alert_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasColumn('ew_forecasts', 'organization_id'));
        $this->assertFalse(Schema::hasColumn('ew_observations', 'organization_id'));
        $this->assertFalse(Schema::hasColumn('ew_exposure_summaries', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('ew_citizen_reports', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('ew_public_tokens', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('ew_forecasts', 'basin_code'));
        $this->assertTrue(Schema::hasColumn('ew_forecasts', 'forecast_at'));
        $this->assertTrue(Schema::hasColumn('ew_forecasts', 'precipitation_mm'));

        foreach (['ew_subscriptions', 'ew_risk_assessments', 'ew_alert_rules', 'ew_alert_logs'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'organization_id'), $table);
        }
    }

    public function test_manifest_lists_all_phase1_tables(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
        )['extra']['lumina'];

        $this->assertSame([], PluginManager::validateManifest($manifest));
        $this->assertEqualsCanonicalizing(
            ['ew_basins', 'ew_forecasts', 'ew_observations', 'ew_exposure_summaries', 'ew_public_tokens', 'ew_citizen_reports', 'ew_subscriptions', 'ew_risk_assessments', 'ew_alert_rules', 'ew_alert_logs', 'ew_risk_latest'],
            $manifest['data']['tables'],
        );
        $this->assertEqualsCanonicalizing(
            ['ew_basins', 'ew_forecasts', 'ew_observations', 'ew_exposure_summaries'],
            $manifest['data']['global_tables'],
        );
        $this->assertContains('api.open-meteo.com', $manifest['outbound_hosts']);
        $this->assertContains('power.larc.nasa.gov', $manifest['outbound_hosts']);
        $this->assertContains('overpass-api.de', $manifest['outbound_hosts']);
    }

    public function test_config_and_routes_are_registered(): void
    {
        $this->assertNotEmpty(config('early-warning.open_meteo.base_url'));
        $this->assertNotEmpty(config('early-warning.risk.horizon_hours'));
        $names = collect(Route::getRoutes())->map->getName();
        $this->assertTrue($names->contains('early-warning.index'));
        $this->assertTrue($names->contains('early-warning.warnings'));
    }

    public function test_risk_latest_view_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('ew_risk_latest') || $this->sqliteViewExists('ew_risk_latest'),
            'ew_risk_latest view',
        );
    }

    private function sqliteViewExists(string $name): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return false;
        }

        return Schema::getConnection()
            ->table('sqlite_master')
            ->where('type', 'view')
            ->where('name', $name)
            ->exists();
    }
}
