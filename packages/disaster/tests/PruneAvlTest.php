<?php

namespace Lumina\Disaster\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\AvlPing;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class PruneAvlTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_prune_deletes_old_pings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        $geom = GeometryWriter::pointFromCoordinates(32.0, 13.0);

        AvlPing::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'U1',
            'recorded_at' => now()->subDays(30),
            'geom' => $geom,
        ]);
        AvlPing::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'U1',
            'recorded_at' => now()->subHours(1),
            'geom' => $geom,
        ]);

        $this->artisan('disaster:prune-avl', ['--sync' => true])->assertSuccessful();

        $this->assertSame(1, AvlPing::query()->count());
    }
}
