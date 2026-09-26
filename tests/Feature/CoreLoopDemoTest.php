<?php

namespace Tests\Feature;

use App\Exceptions\GeoServerException;
use App\Models\Layer;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\GeoServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CoreLoopDemoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user->roles()->attach($editorRole);
    }

    public function test_empty_map_builder_opens_for_a_clean_organization(): void
    {
        $this->actingAs($this->user)
            ->get(route('maps.builder'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Maps/Builder')
                ->where('initialMap.name', 'Untitled map')
                ->where('initialMap.id', null)
                ->where('initialMap.layers', []));
    }

    public function test_map_builder_seeds_the_requested_layer(): void
    {
        $layer = $this->layer();

        $this->actingAs($this->user)
            ->get('/maps/builder?layer='.$layer->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Maps/Builder')
                ->where('initialMap.layers.0.id', $layer->id)
                ->where('initialMap.layers.0.type', 'mvt')
                ->where('initialMap.name', $layer->name));
    }

    public function test_publish_waits_for_geoserver_and_stays_a_draft_when_it_fails(): void
    {
        $layer = $this->layer(['published' => false, 'table_name' => 'parcels']);

        $geo = Mockery::mock(GeoServerService::class);
        $geo->shouldReceive('workspaceExists')->andReturn(true);
        $geo->shouldReceive('datastoreExists')->andReturn(true);
        $geo->shouldReceive('publishLayer')->once()->andThrow(GeoServerException::connectionFailed());
        $this->app->instance(GeoServerService::class, $geo);

        $this->actingAs($this->user)
            ->post(route('layers.publish', $layer))
            ->assertRedirect(route('layers.show', $layer))
            ->assertSessionHas('error');

        $this->assertFalse($layer->fresh()->published);
    }

    public function test_publish_marks_the_layer_only_after_geoserver_accepts_it(): void
    {
        $layer = $this->layer(['published' => false, 'table_name' => 'parcels']);

        $geo = Mockery::mock(GeoServerService::class);
        $geo->shouldReceive('workspaceExists')->andReturn(true);
        $geo->shouldReceive('datastoreExists')->andReturn(true);
        $geo->shouldReceive('publishLayer')->once()->andReturn(true);
        $this->app->instance(GeoServerService::class, $geo);

        $this->actingAs($this->user)
            ->post(route('layers.publish', $layer))
            ->assertRedirect(route('layers.show', $layer))
            ->assertSessionHas('success');

        $fresh = $layer->fresh();
        $this->assertTrue($fresh->published);
        $this->assertSame('org_'.$this->organization->id, $fresh->geoserver_workspace);
    }

    public function test_published_layer_catalog_omits_drafts(): void
    {
        $published = $this->layer(['name' => 'Parcels', 'published' => true]);
        $this->layer(['name' => 'Draft streets', 'published' => false, 'table_name' => 'streets']);

        $this->actingAs($this->user)
            ->getJson('/layers?published=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.published', true);
    }

    public function test_sharing_toggle_returns_to_the_share_page(): void
    {
        $map = Map::create([
            'name' => 'Demo map',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'is_public' => false,
            'layers' => [],
        ]);

        $this->actingAs($this->user)
            ->put(route('maps.update', $map), [
                'name' => $map->name,
                'is_public' => true,
                'return_to_share' => true,
            ])
            ->assertRedirect(route('maps.share', $map))
            ->assertSessionHas('success');

        $this->assertTrue($map->fresh()->is_public);
        $this->assertNotEmpty($map->fresh()->share_token);
    }

    public function test_public_share_serves_tiles_only_for_layers_on_that_map(): void
    {
        $onMap = $this->layer(['name' => 'Parcels']);
        $other = $this->layer(['name' => 'Secret', 'table_name' => 'secret_table']);
        $map = Map::create([
            'name' => 'Shared map',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'is_public' => true,
            'layers' => [
                ['id' => $onMap->id, 'name' => $onMap->name, 'type' => 'mvt'],
            ],
        ]);

        $features = Mockery::mock(FeatureService::class);
        $features->shouldReceive('tile')->once()->andReturn('mvt-bytes');
        $this->app->instance(FeatureService::class, $features);

        $this->get(route('maps.shared.tiles', [
            'token' => $map->share_token,
            'layer' => $onMap->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');

        $this->get(route('maps.shared.tiles', [
            'token' => $map->share_token,
            'layer' => $other->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $map->update(['is_public' => false]);

        $this->get(route('maps.shared.tiles', [
            'token' => $map->share_token,
            'layer' => $onMap->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $this->get("/api/layers/{$onMap->id}/tiles/1/0/0.mvt")
            ->assertUnauthorized();
    }

    public function test_browser_layer_export_returns_to_the_layer_when_the_table_cannot_be_read(): void
    {
        $layer = $this->layer(['table_name' => 'missing_layer_table']);

        $this->actingAs($this->user)
            ->get(route('export.layer.geojson', $layer))
            ->assertRedirect(route('layers.show', $layer))
            ->assertSessionHas('error');

        $this->actingAs($this->user)
            ->getJson(route('export.layer.geojson', $layer))
            ->assertStatus(500)
            ->assertJsonStructure(['error']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function layer(array $overrides = []): Layer
    {
        $project = Project::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        return Layer::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'project_id' => $project->id,
            'table_name' => 'parcels_'.fake()->unique()->numerify('###'),
            'published' => false,
        ], $overrides));
    }
}
