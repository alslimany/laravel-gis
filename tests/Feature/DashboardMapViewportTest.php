<?php

namespace Tests\Feature;

use App\Models\DashboardBoard;
use App\Models\Layer;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardMapViewportTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();

        Role::firstOrCreate(['name' => 'admin'], ['description' => 'Administrator']);
        $this->organization = Organization::factory()->create(['name' => 'Site Ops']);
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->user->roles()->attach(Role::where('name', 'admin')->first());

        Schema::dropIfExists('viewport_points');
        Schema::create('viewport_points', function ($table) {
            $table->increments('id');
            $table->string('status');
            $table->string('geom')->nullable();
        });

        DB::table('viewport_points')->insert([
            ['status' => 'Open', 'geom' => 'POINT(13.19 32.88)'],
            ['status' => 'Open', 'geom' => 'POINT(13.25 32.90)'],
            ['status' => 'Closed', 'geom' => 'POINT(13.22 32.86)'],
        ]);

        $this->layer = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'name' => 'Hydrants',
            'table_name' => 'viewport_points',
            'geometry_type' => 'Point',
            'published' => true,
        ]);
    }

    public function test_layer_map_widget_opens_on_the_layer_extent(): void
    {
        $widgets = $this->actingAs($this->user)
            ->postJson(route('dashboards.preview'), [
                'widgets' => [[
                    'id' => 'site-map',
                    'type' => 'map',
                    'title' => 'Site',
                    'source' => 'layer',
                    'layer_id' => $this->layer->id,
                ]],
            ])
            ->assertOk()
            ->json('widgets');

        $viewport = $widgets[0]['map']['viewport'];

        $this->assertEqualsWithDelta(13.22, $viewport['center'][0], 0.001);
        $this->assertEqualsWithDelta(32.88, $viewport['center'][1], 0.001);
        $this->assertIsInt($viewport['zoom']);
        $this->assertGreaterThanOrEqual(2, $viewport['zoom']);
        $this->assertLessThanOrEqual(18, $viewport['zoom']);
        $this->assertEquals([13.19, 32.86, 13.25, 32.90], $viewport['bounds']);
    }

    public function test_configured_viewport_is_saved_and_served_back(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboards.store'), [
            'name' => 'Site board',
            'is_public' => 1,
            'widgets' => [[
                'id' => 'site-map',
                'type' => 'map',
                'title' => 'Site',
                'source' => 'layer',
                'layer_id' => $this->layer->id,
                'viewport' => [
                    'center' => [13.4, 32.7],
                    'zoom' => 15,
                    'rotation' => 0.25,
                ],
            ]],
        ]);

        $response->assertRedirect();
        $dashboard = DashboardBoard::where('name', 'Site board')->firstOrFail();

        $stored = collect($dashboard->widgets)->firstWhere('id', 'site-map');
        $this->assertSame([13.4, 32.7], $stored['viewport']['center']);
        $this->assertEquals(15, $stored['viewport']['zoom']);

        $widgets = $this->actingAs($this->user)
            ->getJson(route('dashboards.data', $dashboard))
            ->assertOk()
            ->json('widgets');

        $viewport = collect($widgets)->firstWhere('id', 'site-map')['map']['viewport'];
        $this->assertSame([13.4, 32.7], $viewport['center']);
        $this->assertEquals(15, $viewport['zoom']);
        $this->assertEqualsWithDelta(0.25, $viewport['rotation'], 0.0001);
    }

    public function test_bounds_viewport_survives_a_round_trip(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboards.store'), [
            'name' => 'Bounds board',
            'widgets' => [[
                'id' => 'site-map',
                'type' => 'map',
                'title' => 'Site',
                'source' => 'layer',
                'layer_id' => $this->layer->id,
                'viewport' => ['bounds' => [13.0, 32.5, 13.5, 33.0]],
            ]],
        ]);

        $response->assertRedirect();
        $dashboard = DashboardBoard::where('name', 'Bounds board')->firstOrFail();

        $stored = collect($dashboard->widgets)->firstWhere('id', 'site-map');
        $this->assertEquals([13.0, 32.5, 13.5, 33.0], $stored['viewport']['bounds']);
        $this->assertArrayHasKey('center', $stored['viewport']);
        $this->assertArrayHasKey('zoom', $stored['viewport']);
    }

    public function test_widget_viewport_overrides_the_saved_maps_own_view(): void
    {
        $map = Map::create([
            'name' => 'Harbour',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'viewport' => ['center' => [10.0, 10.0], 'zoom' => 4, 'rotation' => 0],
            'layers' => [['id' => $this->layer->id, 'name' => 'Hydrants', 'type' => 'mvt', 'visible' => true]],
            'is_public' => false,
        ]);

        $widgets = $this->actingAs($this->user)
            ->postJson(route('dashboards.preview'), [
                'widgets' => [[
                    'id' => 'harbour',
                    'type' => 'map',
                    'title' => 'Harbour',
                    'source' => 'map',
                    'map_id' => $map->id,
                    'viewport' => ['center' => [13.4, 32.7], 'zoom' => 16],
                ]],
            ])
            ->assertOk()
            ->json('widgets');

        $viewport = $widgets[0]['map']['viewport'];
        $this->assertSame([13.4, 32.7], $viewport['center']);
        $this->assertEquals(16, $viewport['zoom']);
    }

    public function test_saved_map_without_a_viewport_falls_back_to_its_layers(): void
    {
        $map = Map::create([
            'name' => 'Legacy map',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'layers' => [['id' => $this->layer->id, 'name' => 'Hydrants', 'type' => 'mvt', 'visible' => true]],
            'is_public' => false,
        ]);

        $widgets = $this->actingAs($this->user)
            ->postJson(route('dashboards.preview'), [
                'widgets' => [[
                    'id' => 'legacy',
                    'type' => 'map',
                    'title' => 'Legacy',
                    'source' => 'map',
                    'map_id' => $map->id,
                ]],
            ])
            ->assertOk()
            ->json('widgets');

        $viewport = $widgets[0]['map']['viewport'];
        $this->assertEqualsWithDelta(13.22, $viewport['center'][0], 0.001);
        $this->assertEquals([13.19, 32.86, 13.25, 32.90], $viewport['bounds']);
    }

    public function test_layer_without_geometry_keeps_the_world_default(): void
    {
        Schema::drop('viewport_points');
        Schema::create('viewport_points', function ($table) {
            $table->increments('id');
            $table->string('status');
        });
        DB::table('viewport_points')->insert([['status' => 'Open']]);
        Cache::flush();

        $widgets = $this->actingAs($this->user)
            ->postJson(route('dashboards.preview'), [
                'widgets' => [[
                    'id' => 'flat',
                    'type' => 'map',
                    'title' => 'Flat',
                    'source' => 'layer',
                    'layer_id' => $this->layer->id,
                ]],
            ])
            ->assertOk()
            ->json('widgets');

        $this->assertSame(['center' => [0, 20], 'zoom' => 2, 'rotation' => 0], $widgets[0]['map']['viewport']);
    }

    public function test_garbage_viewport_is_dropped_rather_than_stored(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboards.store'), [
            'name' => 'Broken board',
            'widgets' => [[
                'id' => 'site-map',
                'type' => 'map',
                'title' => 'Site',
                'source' => 'layer',
                'layer_id' => $this->layer->id,
                'viewport' => [
                    'center' => ['north', 'south'],
                    'zoom' => 'deep',
                    'bounds' => [50, 50, 10, 10],
                ],
            ]],
        ]);

        $response->assertRedirect();
        $dashboard = DashboardBoard::where('name', 'Broken board')->firstOrFail();

        $stored = collect($dashboard->widgets)->firstWhere('id', 'site-map');
        $this->assertArrayNotHasKey('viewport', $stored);
    }

    public function test_public_dashboard_serves_the_configured_viewport_to_guests(): void
    {
        $dashboard = DashboardBoard::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'Public site board',
            'widgets' => [[
                'id' => 'site-map',
                'type' => 'map',
                'title' => 'Site',
                'source' => 'layer',
                'layer_id' => $this->layer->id,
                'viewport' => ['center' => [13.4, 32.7], 'zoom' => 14],
            ]],
            'is_public' => true,
        ]);

        $widgets = $this->getJson(route('dashboards.public.data', $dashboard->share_token))
            ->assertOk()
            ->json('widgets');

        $viewport = collect($widgets)->firstWhere('id', 'site-map')['map']['viewport'];
        $this->assertSame([13.4, 32.7], $viewport['center']);
        $this->assertEquals(14, $viewport['zoom']);
    }

    public function test_map_update_persists_a_valid_viewport(): void
    {
        $map = Map::create([
            'name' => 'Harbour',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'layers' => [],
            'is_public' => false,
        ]);

        $this->actingAs($this->user)
            ->put(route('maps.update', $map), [
                'name' => $map->name,
                'viewport' => [
                    'center' => [13.4, 32.7],
                    'zoom' => 14,
                    'rotation' => -0.5,
                    'bounds' => [13.0, 32.5, 13.8, 33.0],
                ],
            ])
            ->assertRedirect(route('maps.show', $map))
            ->assertSessionHas('success');

        $viewport = $map->fresh()->viewport;
        $this->assertEquals([13.4, 32.7], $viewport['center']);
        $this->assertEquals(14, $viewport['zoom']);
        $this->assertEqualsWithDelta(-0.5, $viewport['rotation'], 0.0001);
        $this->assertEquals([13.0, 32.5, 13.8, 33.0], $viewport['bounds']);
    }

    public function test_map_update_rejects_an_out_of_range_viewport(): void
    {
        $map = Map::create([
            'name' => 'Harbour',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'viewport' => ['center' => [13.4, 32.7], 'zoom' => 14],
            'layers' => [],
            'is_public' => false,
        ]);

        $this->actingAs($this->user)
            ->from(route('maps.show', $map))
            ->put(route('maps.update', $map), [
                'name' => $map->name,
                'viewport' => [
                    'center' => [999, 999],
                    'zoom' => 99,
                ],
            ])
            ->assertSessionHasErrors(['viewport.center.0', 'viewport.center.1', 'viewport.zoom']);

        $this->assertSame([13.4, 32.7], $map->fresh()->viewport['center']);
    }

    public function test_map_builder_seeded_from_a_layer_starts_on_its_extent(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('maps.builder').'?layer='.$this->layer->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Maps/Builder')
                ->where('initialMap.viewport.bounds', [13.19, 32.86, 13.25, 32.90]));

        $this->assertNotNull($response->viewData('page')['props']['initialMap']['viewport']['center']);
    }

    public function test_map_seeded_from_a_layer_without_geometry_has_no_viewport(): void
    {
        $flat = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'name' => 'Attributes only',
            'table_name' => 'viewport_points',
            'geometry_type' => 'Geometry',
        ]);

        // A table with a geom column would get an extent; one without stays on the default.
        Schema::table('viewport_points', function ($table) {
            $table->dropColumn('geom');
        });
        Cache::flush();

        $response = $this->actingAs($this->user)
            ->get(route('maps.builder').'?layer='.$flat->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Maps/Builder'));

        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('initialMap', $props);
        $this->assertNull($props['initialMap']['viewport']);
    }
}
