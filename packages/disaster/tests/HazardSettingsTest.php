<?php

namespace Lumina\Disaster\Tests;

use App\Models\OrganizationPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class HazardSettingsTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_admin_can_update_layer_ids_in_organization_plugins_settings(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/hazards', [
                'floodplain_layer_id' => 42,
                'ew_risk_layer_id' => 7,
                'retention_avl_days' => 21,
            ])
            ->assertOk()
            ->assertJsonPath('settings.floodplain_layer_id', 42)
            ->assertJsonPath('settings.ew_risk_layer_id', 7)
            ->assertJsonPath('settings.retention_avl_days', 21);

        $row = OrganizationPlugin::query()
            ->where('organization_id', $this->disasterOrg->id)
            ->where('plugin_key', 'disaster')
            ->first();

        $this->assertSame(42, $row->settings['floodplain_layer_id']);
    }
}
