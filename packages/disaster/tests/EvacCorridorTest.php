<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class EvacCorridorTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_evac_corridor_crud(): void
    {
        $shelter = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'SH-1',
            'type' => 'shelter',
            'name' => 'Shelter',
            'geom' => GeometryWriter::pointFromCoordinates(32.87, 13.18),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/evac-corridors', [
                'name' => 'Coastal route',
                'shelter_site_id' => $shelter->id,
                'geojson' => [
                    'type' => 'LineString',
                    'coordinates' => [[13.18, 32.87], [13.19, 32.88]],
                ],
            ])
            ->assertCreated();

        $corridor = EvacCorridor::query()->where('name', 'Coastal route')->firstOrFail();
        $this->assertSame($shelter->id, $corridor->shelter_site_id);
    }
}
