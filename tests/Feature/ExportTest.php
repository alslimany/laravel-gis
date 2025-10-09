<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Layer $layer;
    protected Map $map;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        $editorRole = \App\Models\Role::create(['name' => 'editor', 'description' => 'Editor']);

        // Create organization and user
        $this->organization = Organization::factory()->create();
        
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user->roles()->attach($editorRole);

        // Create a project and layer
        $project = Project::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->layer = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'project_id' => $project->id,
        ]);

        // Create a map
        $this->map = Map::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Map',
        ]);
    }

    public function test_user_can_export_map_config()
    {
        $response = $this->actingAs($this->user)
            ->get(route('export.map.config', $this->map));

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'name',
                'description',
                'basemap',
                'viewport',
                'layers',
                'exported_at',
            ]);
    }

    public function test_guest_cannot_export_map_config()
    {
        $response = $this->get(route('export.map.config', $this->map));

        $response->assertRedirect(route('login'));
    }

    public function test_user_cannot_export_map_from_different_organization()
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        
        $otherMap = Map::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('export.map.config', $otherMap));

        $response->assertStatus(403);
    }

    public function test_user_can_prepare_map_export()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/export/map/'.$this->map->id.'/prepare');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'map' => [
                    'name',
                    'description',
                    'basemap',
                    'viewport',
                    'layers',
                ],
            ]);
    }

    public function test_export_query_results_requires_layer_id()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/export/query-results', [
                'features' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['layer_id']);
    }

    public function test_export_query_results_requires_features()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/export/query-results', [
                'layer_id' => $this->layer->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['features']);
    }

    public function test_user_can_export_query_results()
    {
        $features = [
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [0, 0],
                ],
                'properties' => [
                    'name' => 'Test Feature',
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/export/query-results', [
                'layer_id' => $this->layer->id,
                'features' => $features,
            ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/geo+json')
            ->assertJsonStructure([
                'type',
                'features',
            ]);
    }
}
