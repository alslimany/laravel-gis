<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class PublicPortalTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();

        $issued = PublicToken::issue($this->disasterOrg->id, 'public');
        $this->plainToken = $issued['plain'];

        Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'PUB-1',
            'title' => 'Road washout',
            'status' => 'open',
            'opened_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.8, 13.1),
        ]);

        AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'SECRET-UNIT',
            'resource_class' => 'ems',
            'geom' => GeometryWriter::pointFromCoordinates(32.8, 13.1),
        ]);
    }

    public function test_public_page_shows_advisory_not_avl(): void
    {
        $this->get('/dm/public/'.$this->plainToken)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Public')
                ->has('advisory')
                ->where('avl_included', false)
                ->has('open_incidents', 1)
            );

        $feed = $this->getJson('/dm/public/'.$this->plainToken.'/feed')
            ->assertOk()
            ->json();

        $this->assertFalse($feed['avl_included']);
        $this->assertArrayNotHasKey('avl_units', $feed);
        $this->assertNotEmpty($feed['advisory']);
    }
}
