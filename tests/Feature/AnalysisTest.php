<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Layer $layer;

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
    }

    public function test_user_can_calculate_buffer()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/analysis/buffer', [
                'wkt' => 'POINT(0 0)',
                'distance' => 100,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'buffered_wkt',
                'buffered_geojson',
            ]);
    }

    public function test_buffer_requires_valid_inputs()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/analysis/buffer', [
                'wkt' => 'INVALID',
            ]);

        $response->assertStatus(400)
            ->assertJsonStructure(['error']);
    }

    public function test_user_can_measure_distance()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/analysis/measure-distance', [
                'lat1' => 0,
                'lon1' => 0,
                'lat2' => 0,
                'lon2' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'distance_meters',
                'distance_km',
                'distance_miles',
            ]);
    }

    public function test_user_can_measure_area()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/analysis/measure-area', [
                'wkt' => 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'area_sqm',
                'area_sqkm',
                'area_hectares',
                'area_acres',
            ]);
    }

    public function test_guest_cannot_access_analysis_endpoints()
    {
        $response = $this->postJson('/api/analysis/buffer', [
            'wkt' => 'POINT(0 0)',
            'distance' => 100,
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_organization_cannot_perform_spatial_query()
    {
        $userWithoutOrg = User::factory()->create(['organization_id' => null]);

        $response = $this->actingAs($userWithoutOrg)
            ->postJson('/api/analysis/spatial-query', [
                'layer_id' => $this->layer->id,
                'operation' => 'within',
                'wkt' => 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_query_layer_from_different_organization()
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        
        $otherProject = Project::factory()->create([
            'organization_id' => $otherOrg->id,
        ]);

        $otherLayer = Layer::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
            'project_id' => $otherProject->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/analysis/spatial-query', [
                'layer_id' => $otherLayer->id,
                'operation' => 'within',
                'wkt' => 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))',
            ]);

        $response->assertStatus(403);
    }
}
