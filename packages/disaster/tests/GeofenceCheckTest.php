<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Jobs\GeofenceCheckJob;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class GeofenceCheckTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_geofence_job_logs_when_unit_near_open_incident(): void
    {
        Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'INC-1',
            'title' => 'Open incident',
            'status' => 'open',
            'opened_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'UNIT-1',
            'resource_class' => 'ambulance',
            'geom' => GeometryWriter::pointFromCoordinates(32.8801, 13.1901),
        ]);

        $written = (new GeofenceCheckJob($this->disasterOrg->id))->handle();

        $this->assertGreaterThanOrEqual(1, $written);
        $this->assertTrue(
            DisseminationLog::query()
                ->where('organization_id', $this->disasterOrg->id)
                ->where('channel', 'geofence')
                ->exists()
        );
    }
}
