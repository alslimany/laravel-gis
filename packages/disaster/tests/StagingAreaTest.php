<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class StagingAreaTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_staging_area_crud(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/staging-areas', [
                'staging_id' => 'ST-1',
                'name' => 'North lot',
                'max_vehicle_cap' => 25,
                'fuel_availability' => true,
            ])
            ->assertCreated();

        $area = StagingArea::query()->where('staging_id', 'ST-1')->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/staging-areas/'.$area->id, ['name' => 'North staging'])
            ->assertOk()
            ->assertJsonPath('data.name', 'North staging');

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/staging-areas')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
