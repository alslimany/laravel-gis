<?php

namespace Lumina\Disaster\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\AvlPing;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_ingest_incident_and_avl_ping(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/api/v1/incidents', [
                'external_id' => 'CAD-100',
                'title' => 'Structure fire',
                'geometry' => ['type' => 'Point', 'coordinates' => [13.19, 32.88]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'CAD-100');

        $this->assertSame(1, Incident::query()->where('external_id', 'CAD-100')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00'));

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/api/v1/avl/pings', [
                'unit_id' => 'EMS-42',
                'resource_class' => 'ems',
                'geometry' => ['type' => 'Point', 'coordinates' => [13.19, 32.88]],
                'speed_kmh' => 45,
            ])
            ->assertCreated();

        $this->assertSame(1, AvlUnit::query()->where('unit_id', 'EMS-42')->count());
        $this->assertSame(1, AvlPing::query()->where('unit_id', 'EMS-42')->count());
    }

    public function test_avl_ping_rejects_excessive_skew(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00'));

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/api/v1/avl/pings', [
                'unit_id' => 'LAW-1',
                'resource_class' => 'law',
                'recorded_at' => '2026-10-27 10:00:00',
                'geometry' => ['type' => 'Point', 'coordinates' => [13.0, 32.0]],
            ])
            ->assertUnprocessable();
    }

    public function test_closure_ingest_validation(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/api/v1/closures', [
                'external_id' => 'CL-1',
            ])
            ->assertUnprocessable();

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/api/v1/closures', [
                'external_id' => 'CL-1',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[13.1, 32.1], [13.2, 32.2]],
                ],
            ])
            ->assertCreated();

        $this->assertSame(1, Closure::query()->where('external_id', 'CL-1')->count());
    }
}
