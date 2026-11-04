<?php

namespace Lumina\Disaster\Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class MedicalFacilityTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_crud_and_org_isolation(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/medical-facilities', [
                'facility_id' => 'MF-1',
                'name' => 'Central Hospital',
                'bed_capacity' => 100,
                'latitude' => 32.88,
                'longitude' => 13.19,
            ])
            ->assertCreated()
            ->assertJsonPath('data.facility_id', 'MF-1');

        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $foreign = MedicalFacility::query()->create([
            'organization_id' => $otherOrg->id,
            'facility_id' => 'MF-X',
            'name' => 'Other',
            'geom' => GeometryWriter::pointFromCoordinates(32.0, 13.0),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/medical-facilities/'.$foreign->id, ['name' => 'Hacked'])
            ->assertNotFound();

        $mine = MedicalFacility::query()->where('facility_id', 'MF-1')->firstOrFail();
        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/medical-facilities/'.$mine->id, ['available_beds' => 40])
            ->assertOk()
            ->assertJsonPath('data.available_beds', 40);

        $this->actingAs($this->disasterAdmin)
            ->deleteJson('/x/disaster/medical-facilities/'.$mine->id)
            ->assertOk();

        $this->assertDatabaseMissing('dm_medical_facilities', ['id' => $mine->id]);
    }
}
