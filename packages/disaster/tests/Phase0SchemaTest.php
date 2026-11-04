<?php

namespace Lumina\Disaster\Tests;

use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class Phase0SchemaTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_phase0_tables_exist(): void
    {
        foreach ([
            'dm_medical_facilities',
            'dm_staging_areas',
            'dm_critical_sites',
            'dm_evac_corridors',
            'dm_exposure_summaries',
            'dm_incidents',
            'dm_closures',
            'dm_avl_units',
            'dm_avl_pings',
            'dm_public_tokens',
            'dm_dissemination_logs',
            'dm_damage_points',
            'dm_activations',
            'dm_infrastructure_conditions',
            'dm_warning_recipients',
            'dm_escalation_rules',
            'dm_warning_audit_log',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumn($table, 'organization_id'), $table);
        }

        $this->assertTrue(Schema::hasTable('dm_warning_acks'));

        foreach (['capacity_mcm', 'height_m', 'year_built', 'spillway_capacity', 'downstream_zone_layer_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('dm_critical_sites', $column), $column);
        }
    }

    public function test_manifest_lists_all_tables(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
        )['extra']['lumina'];

        $this->assertSame([], PluginManager::validateManifest($manifest));
        $this->assertContains('dm_activations', $manifest['data']['tables']);
        $this->assertContains('dm_infrastructure_conditions', $manifest['data']['tables']);
        $this->assertContains('dm_warning_recipients', $manifest['data']['tables']);
        $this->assertContains('dm_warning_acks', $manifest['data']['tables']);
        $this->assertContains('dm_escalation_rules', $manifest['data']['tables']);
        $this->assertContains('dm_warning_audit_log', $manifest['data']['tables']);
        $this->assertSame(
            count($manifest['data']['tables']),
            count(array_unique($manifest['data']['tables'])),
        );
    }
}
