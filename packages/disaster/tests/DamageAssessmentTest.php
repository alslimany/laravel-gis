<?php

namespace Lumina\Disaster\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Services\DamageAggregationService;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\Habitability;
use Lumina\Disaster\Support\Enums\Lifeline;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class DamageAssessmentTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_create_assessment_with_photos_using_fake_storage(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->disasterAdmin)
            ->post('/x/disaster/damage-points', [
                'damage_level' => DamageLevel::Moderate->value,
                'structure_type' => 'residential',
                'habitability' => Habitability::Limited->value,
                'urgent_needs' => ['water', 'shelter'],
                'people_affected' => 12,
                'hazards_present' => ['gas_leak'],
                'notes' => 'Partial collapse',
                'latitude' => 32.88,
                'longitude' => 13.19,
                'props' => ['zone' => 'Old City'],
                'photos' => [
                    UploadedFile::fake()->image('front.jpg'),
                    UploadedFile::fake()->image('side.jpg'),
                ],
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.review_status', DamageReviewStatus::Submitted->value)
            ->assertJsonPath('data.structure_type', 'residential')
            ->assertJsonCount(2, 'data.photos');

        $point = DamagePoint::query()->firstOrFail();
        $this->assertSame(DamageReviewStatus::Submitted, $point->review_status);
        $this->assertCount(2, $point->photos);
        foreach ($point->photos as $photo) {
            Storage::disk('local')->assertExists($photo->storage_path);
        }
    }

    public function test_review_then_verify_flow_and_lifeline_link(): void
    {
        $component = LifelineComponent::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'lifeline' => Lifeline::WaterSystems,
            'name' => 'North plant',
        ]);

        $point = DamagePoint::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'damage_level' => DamageLevel::Severe,
            'structure_type' => 'plant',
            'review_status' => DamageReviewStatus::Submitted,
            'lifeline_component_id' => $component->id,
            'assessed_at' => now(),
            'assessed_by' => $this->disasterAdmin->id,
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
            'props' => ['zone' => 'North'],
        ]);

        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->actingAs($member)
            ->postJson('/x/disaster/damage-points/'.$point->id.'/review', [
                'review_status' => DamageReviewStatus::Reviewed->value,
            ])
            ->assertForbidden();

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/damage-points/'.$point->id.'/review', [
                'review_status' => DamageReviewStatus::Reviewed->value,
                'reviewer_comments' => 'Looks complete',
            ])
            ->assertOk()
            ->assertJsonPath('data.review_status', DamageReviewStatus::Reviewed->value);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/damage-points/'.$point->id.'/review', [
                'review_status' => DamageReviewStatus::Verified->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.review_status', DamageReviewStatus::Verified->value);

        $this->assertDatabaseHas('dm_lifeline_status', [
            'component_id' => $component->id,
            'status' => LifelineComponentStatus::Impacted->value,
            'organization_id' => $this->disasterOrg->id,
        ]);

        $this->assertSame(1, LifelineStatus::query()->where('component_id', $component->id)->count());
    }

    public function test_aggregates_match_verified_only_and_exclude_unverified(): void
    {
        DamagePoint::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'damage_level' => DamageLevel::Destroyed,
            'review_status' => DamageReviewStatus::Verified,
            'people_affected' => 10,
            'assessed_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
            'props' => ['zone' => 'A'],
        ]);
        DamagePoint::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'damage_level' => DamageLevel::Minor,
            'review_status' => DamageReviewStatus::Verified,
            'people_affected' => 2,
            'assessed_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.87, 13.18),
            'props' => ['neighborhood' => 'A'],
        ]);
        DamagePoint::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'damage_level' => DamageLevel::Severe,
            'review_status' => DamageReviewStatus::Submitted,
            'people_affected' => 99,
            'assessed_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.86, 13.17),
            'props' => ['zone' => 'A'],
        ]);
        DamagePoint::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'damage_level' => DamageLevel::Moderate,
            'review_status' => DamageReviewStatus::Reviewed,
            'people_affected' => 5,
            'assessed_at' => now(),
            'props' => ['zone' => 'B'],
        ]);

        $payload = (new DamageAggregationService)->aggregate($this->disasterOrg->id);

        $this->assertSame(2, $payload['official']['total']);
        $this->assertSame(12, $payload['official']['people_affected']);
        $this->assertSame(1, $payload['official']['by_level'][DamageLevel::Destroyed->value]);
        $this->assertSame(1, $payload['official']['by_level'][DamageLevel::Minor->value]);
        $this->assertSame(0, $payload['official']['by_level'][DamageLevel::Severe->value]);
        $this->assertSame(4, $payload['all']['total']);
        $this->assertCount(2, $payload['table']);
        $this->assertSame('FeatureCollection', $payload['geojson']['type']);
        $this->assertCount(2, $payload['geojson']['features']);

        $zoneA = collect($payload['official']['by_zone'])->firstWhere('zone', 'A');
        $this->assertNotNull($zoneA);
        $this->assertSame(2, $zoneA['total']);
        $this->assertSame(12, $zoneA['people_affected']);

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/damage-points/aggregates')
            ->assertOk()
            ->assertJsonPath('data.official.total', 2)
            ->assertJsonPath('data.all.total', 4);
    }

    public function test_rejects_more_than_five_photos(): void
    {
        Storage::fake('local');

        $photos = [];
        for ($i = 0; $i < 6; $i++) {
            $photos[] = UploadedFile::fake()->image("p{$i}.jpg");
        }

        $this->actingAs($this->disasterAdmin)
            ->post('/x/disaster/damage-points', [
                'damage_level' => DamageLevel::Minor->value,
                'latitude' => 32.88,
                'longitude' => 13.19,
                'photos' => $photos,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_incident_imagery_register_pre_post(): void
    {
        $incident = Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'INC-IMG-1',
            'title' => 'Flood',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/incidents/'.$incident->id.'/imagery', [
                'role' => 'pre',
                'layer_id' => 42,
                'label' => 'Before',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'pre')
            ->assertJsonPath('data.layer_id', 42);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/incidents/'.$incident->id.'/imagery', [
                'role' => 'post',
                'layer_id' => 43,
            ])
            ->assertCreated();

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/incidents/'.$incident->id.'/imagery')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
