<?php

namespace Lumina\Disaster\Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class DamagePointTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_crud_org_isolation_and_damage_level_validation(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/damage-points', [
                'damage_level' => 'Invalid',
                'latitude' => 32.88,
                'longitude' => 13.19,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/damage-points', [
                'external_id' => 'DA-1',
                'damage_level' => DamageLevel::Severe->value,
                'notes' => 'Roof collapse',
                'latitude' => 32.88,
                'longitude' => 13.19,
            ])
            ->assertCreated()
            ->assertJsonPath('data.damage_level', DamageLevel::Severe->value)
            ->assertJsonPath('data.review_status', 'submitted');

        $otherOrg = Organization::factory()->create();
        $foreign = DamagePoint::query()->create([
            'organization_id' => $otherOrg->id,
            'damage_level' => DamageLevel::Minor->value,
            'assessed_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.0, 13.0),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/damage-points/'.$foreign->id, ['notes' => 'Hacked'])
            ->assertNotFound();

        $mine = DamagePoint::query()->where('external_id', 'DA-1')->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/damage-points/'.$mine->id, ['damage_level' => DamageLevel::Destroyed->value])
            ->assertOk()
            ->assertJsonPath('data.damage_level', DamageLevel::Destroyed->value);

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/damage-points')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->disasterAdmin)
            ->deleteJson('/x/disaster/damage-points/'.$mine->id)
            ->assertOk();

        $this->assertDatabaseMissing('dm_damage_points', ['id' => $mine->id]);
    }

    public function test_org_member_with_field_can_create_damage_point(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->actingAs($member)
            ->postJson('/x/disaster/damage-points', [
                'damage_level' => DamageLevel::None->value,
                'latitude' => 32.87,
                'longitude' => 13.18,
            ])
            ->assertCreated();
    }
}
