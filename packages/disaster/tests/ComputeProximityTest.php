<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\ExposureSummary;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class ComputeProximityTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_compute_creates_exposure_summaries(): void
    {
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'NEAR',
            'name' => 'Near hospital',
            'geom' => GeometryWriter::pointFromCoordinates(32.8800, 13.1900),
        ]);
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'FAR',
            'name' => 'Far hospital',
            'geom' => GeometryWriter::pointFromCoordinates(33.5000, 13.5000),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/exposure/compute', [
                'hazard_key' => 'test-flood',
                'latitude' => 32.8801,
                'longitude' => 13.1901,
                'radius_m' => 2000,
                'sync' => true,
            ])
            ->assertOk()
            ->assertJsonPath('written', 2);

        $this->assertSame(
            2,
            ExposureSummary::query()
                ->where('hazard_key', 'test-flood')
                ->where('asset_type', 'medical_facility')
                ->count(),
        );
        $near = ExposureSummary::query()
            ->forOrganization($this->disasterOrg->id)
            ->where('asset_type', 'medical_facility')
            ->where('in_hazard', true)
            ->count();
        $this->assertGreaterThanOrEqual(1, $near);

        $zone = ExposureSummary::query()
            ->forOrganization($this->disasterOrg->id)
            ->where('hazard_key', 'test-flood')
            ->where('asset_type', 'zone')
            ->first();
        $this->assertNotNull($zone);
        $this->assertNotNull($zone->risk_index);
    }
}
