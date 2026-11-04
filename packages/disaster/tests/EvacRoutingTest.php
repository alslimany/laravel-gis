<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Jobs\RecomputeEvacRoutesJob;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacAssignment;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\RoadNetworkEdge;
use Lumina\Disaster\Services\EvacuationRoutingService;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class EvacRoutingTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
        config(['disaster.routing.engine' => 'null']);
    }

    public function test_route_avoids_active_closures_via_blocked_edges(): void
    {
        $shelter = $this->makeShelter('SH-1', 13.20, 32.90, capacity: 500);

        // Two parallel corridors: direct (will be closed) and detour.
        $direct = RoadNetworkEdge::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'edge-direct',
            'from_node' => 'A',
            'to_node' => 'B',
            'length_m' => 100,
            'free_flow_s' => 10,
            'is_blocked' => false,
            'props' => [
                'coordinates' => [[13.18, 32.87], [13.20, 32.90]],
            ],
            'geom' => GeometryWriter::lineFromGeoJson([
                'type' => 'LineString',
                'coordinates' => [[13.18, 32.87], [13.20, 32.90]],
            ]),
        ]);

        RoadNetworkEdge::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'edge-detour',
            'from_node' => 'A',
            'to_node' => 'B',
            'length_m' => 400,
            'free_flow_s' => 40,
            'is_blocked' => false,
            'props' => [
                'coordinates' => [[13.18, 32.87], [13.19, 32.88], [13.20, 32.90]],
            ],
            'geom' => GeometryWriter::lineFromGeoJson([
                'type' => 'LineString',
                'coordinates' => [[13.18, 32.87], [13.19, 32.88], [13.20, 32.90]],
            ]),
        ]);

        Closure::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'edge-direct',
            'closure_type' => 'road',
            'source' => 'console',
            'valid_from' => now()->subHour(),
            'valid_to' => now()->addDay(),
            'geom' => GeometryWriter::lineFromGeoJson([
                'type' => 'LineString',
                'coordinates' => [[13.18, 32.87], [13.20, 32.90]],
            ]),
        ]);

        /** @var EvacuationRoutingService $service */
        $service = $this->app->make(EvacuationRoutingService::class);

        $run = $service->recompute($this->disasterOrg->id, null, [
            [
                'zone_key' => 'zone-a',
                'population' => 50,
                'lat' => 32.87,
                'lon' => 13.18,
            ],
        ], (int) $this->disasterAdmin->id);

        $this->assertSame('completed', $run->status);
        $this->assertTrue((bool) $direct->fresh()->is_blocked);

        $corridor = EvacCorridor::query()
            ->forOrganization($this->disasterOrg->id)
            ->where('source', 'computed')
            ->firstOrFail();

        $edgeIds = data_get($corridor->props, 'edge_ids', []);
        $this->assertNotContains($direct->id, $edgeIds);
        $this->assertSame($shelter->id, $corridor->shelter_site_id);
        $this->assertGreaterThan(100, (float) $corridor->distance_m);
    }

    public function test_assignments_never_exceed_shelter_capacity_without_override(): void
    {
        $shelter = $this->makeShelter('SH-CAP', 13.20, 32.90, capacity: 100);

        /** @var EvacuationRoutingService $service */
        $service = $this->app->make(EvacuationRoutingService::class);

        $run = $service->recompute($this->disasterOrg->id, null, [
            [
                'zone_key' => 'z1',
                'population' => 80,
                'lat' => 32.87,
                'lon' => 13.18,
            ],
            [
                'zone_key' => 'z2',
                'population' => 50,
                'lat' => 32.88,
                'lon' => 13.19,
            ],
        ]);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) data_get($run->metrics, 'assigned'));
        $this->assertSame(1, (int) data_get($run->metrics, 'skipped'));

        $load = $service->shelterLoad($this->disasterOrg->id, $shelter->id);
        $this->assertLessThanOrEqual(100, $load);

        $this->expectException(ValidationException::class);
        $service->overrideAssignment(
            $this->disasterOrg->id,
            'z-overflow',
            $shelter->id,
            50,
            null,
        );
    }

    public function test_override_with_reason_succeeds_when_over_capacity(): void
    {
        $shelter = $this->makeShelter('SH-OVR', 13.20, 32.90, capacity: 40);

        /** @var EvacuationRoutingService $service */
        $service = $this->app->make(EvacuationRoutingService::class);

        $assignment = $service->overrideAssignment(
            $this->disasterOrg->id,
            'zone-override',
            $shelter->id,
            60,
            'Commander authorized overflow for medical convoy',
            null,
            (int) $this->disasterAdmin->id,
        );

        $this->assertSame(60, $assignment->population);
        $this->assertNotNull($assignment->override_reason);
        $this->assertSame(60, $service->shelterLoad($this->disasterOrg->id, $shelter->id));
    }

    public function test_compute_endpoint_requires_manage_and_lists_with_view(): void
    {
        Bus::fake([RecomputeEvacRoutesJob::class]);

        $this->makeShelter('SH-HTTP', 13.20, 32.90, capacity: 200);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/evac-routing/compute', [
                'sync' => true,
                'zones' => [
                    [
                        'zone_key' => 'http-zone',
                        'population' => 25,
                        'lat' => 32.87,
                        'lon' => 13.18,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/evac-routing/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $assignment = EvacAssignment::query()->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evac-routing/assignments/{$assignment->id}/override", [
                'shelter_site_id' => $assignment->shelter_site_id,
                'population' => 30,
                'override_reason' => 'Adjusted after roll call',
            ])
            ->assertOk()
            ->assertJsonPath('data.population', 30);
    }

    public function test_closure_store_dispatches_recompute_job(): void
    {
        Bus::fake([RecomputeEvacRoutesJob::class]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/closures', [
                'external_id' => 'CL-ROUTE-1',
                'geojson_text' => '{"type":"LineString","coordinates":[[13.1,32.1],[13.2,32.2]]}',
            ])
            ->assertCreated();

        Bus::assertDispatched(RecomputeEvacRoutesJob::class, function (RecomputeEvacRoutesJob $job): bool {
            return $job->organizationId === (int) $this->disasterOrg->id;
        });
    }

    private function makeShelter(string $siteId, float $lon, float $lat, int $capacity): CriticalSite
    {
        return CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => $siteId,
            'type' => CriticalSiteType::Shelter->value,
            'name' => 'Shelter '.$siteId,
            'capacity' => $capacity,
            'geom' => GeometryWriter::pointFromCoordinates($lat, $lon),
        ]);
    }
}
