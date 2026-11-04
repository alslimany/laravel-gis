<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

/** HOME-1: the module home's "Activations" stat counts live activations (proposed + active), like the COP. */
class HomeActivationsCountTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_activations_stat_counts_proposed_and_active_only(): void
    {
        foreach (['proposed', 'active', 'active', 'closed', 'cancelled'] as $i => $status) {
            Activation::query()->create([
                'organization_id' => $this->disasterOrg->id,
                'source' => 'manual',
                'source_alert_id' => 'HOME-'.$i,
                'hazard_type' => 'flood',
                'is_exercise' => true,
                'status' => $status,
            ]);
        }

        $this->actingAs($this->disasterAdmin)->getJson('/x/disaster')
            ->assertOk()
            ->assertJsonPath('counts.activations', 3)
            ->assertJsonPath('counts.proposed_activations', 1);
    }
}
