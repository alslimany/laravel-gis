<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class CriticalSiteTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_critical_site_crud_and_type_validation(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/critical-sites', [
                'site_id' => 'CS-1',
                'type' => 'not_a_type',
                'name' => 'Bad',
            ])
            ->assertUnprocessable();

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/critical-sites', [
                'site_id' => 'CS-1',
                'type' => 'shelter',
                'name' => 'School A',
                'capacity' => 500,
                'latitude' => 32.87,
                'longitude' => 13.18,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'shelter');

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/critical-sites', [
                'site_id' => 'DAM-CS',
                'type' => 'dam',
                'name' => 'Check Dam',
                'capacity_mcm' => 3.25,
                'height_m' => 22,
                'year_built' => 1999,
                'spillway_capacity' => 100.5,
                'downstream_zone_layer_id' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'dam')
            ->assertJsonPath('data.downstream_zone_layer_id', 15);
    }
}
