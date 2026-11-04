<?php

namespace Lumina\Disaster\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_member_cannot_update_hazards_but_can_view_avl_with_telemetry(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->actingAs($member)
            ->putJson('/x/disaster/hazards', ['floodplain_layer_id' => 1])
            ->assertForbidden();

        $this->actingAs($member)
            ->getJson('/x/disaster/avl/units')
            ->assertOk();
    }
}
