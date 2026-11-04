<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class DisasterIndexTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    public function test_index_returns_inertia_when_enabled(): void
    {
        $this->installDisasterPlugin(true);

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Index')
                ->where('plugin', 'disaster')
                ->has('advisory')
                ->has('counts')
            );
    }

    public function test_index_returns_404_when_disabled_for_org(): void
    {
        $this->installDisasterPlugin(false);

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster')
            ->assertNotFound();
    }
}
