<?php

namespace Tests\Feature;

use App\Helpers\SpatialHelper;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpatialDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgis_extension_is_enabled(): void
    {
        $result = DB::selectOne('SELECT PostGIS_Version()');
        $this->assertNotNull($result);
    }

    public function test_user_can_be_created_with_location(): void
    {
        $user = User::factory()->create([
            'location' => DB::raw('ST_MakePoint(-122.4194, 37.7749)::geography'),
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);

        $result = DB::selectOne(
            'SELECT ST_AsText(location) as location FROM users WHERE id = ?',
            [$user->id]
        );

        $this->assertEquals('POINT(-122.4194 37.7749)', $result->location);
    }

    public function test_organization_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create(['user_id' => $user->id]);

        $this->assertEquals($user->id, $organization->user->id);
    }

    public function test_project_belongs_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertEquals($organization->id, $project->organization->id);
    }

    public function test_project_can_be_created_with_bounding_box(): void
    {
        $project = Project::factory()->create([
            'bounding_box' => DB::raw("ST_GeomFromText('POLYGON((-122.5 37.7, -122.3 37.7, -122.3 37.8, -122.5 37.8, -122.5 37.7))', 4326)"),
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
        ]);

        $result = DB::selectOne(
            'SELECT ST_AsText(bounding_box) as bbox FROM projects WHERE id = ?',
            [$project->id]
        );

        $this->assertStringContainsString('POLYGON', $result->bbox);
    }

    public function test_spatial_helper_converts_wkt_to_geojson(): void
    {
        $wkt = 'POINT(-122.4194 37.7749)';
        $geojson = SpatialHelper::wktToGeoJson($wkt);

        $this->assertIsArray($geojson);
        $this->assertEquals('Point', $geojson['type']);
        $this->assertEquals([-122.4194, 37.7749], $geojson['coordinates']);
    }

    public function test_spatial_helper_converts_geojson_to_wkt(): void
    {
        $geojson = [
            'type' => 'Point',
            'coordinates' => [-122.4194, 37.7749],
        ];

        $wkt = SpatialHelper::geoJsonToWkt($geojson);

        $this->assertEquals('POINT(-122.4194 37.7749)', $wkt);
    }

    public function test_spatial_helper_calculates_distance(): void
    {
        $distance = SpatialHelper::distance(37.7749, -122.4194, 37.7849, -122.4094);

        $this->assertGreaterThan(0, $distance);
        $this->assertLessThan(2000, $distance); // Should be less than 2km
    }

    public function test_user_within_distance_scope(): void
    {
        User::factory()->create([
            'location' => DB::raw('ST_MakePoint(-122.4194, 37.7749)::geography'),
        ]);

        User::factory()->create([
            'location' => DB::raw('ST_MakePoint(0, 0)::geography'),
        ]);

        $users = User::withinDistance('location', 37.7749, -122.4194, 10000)->get();

        $this->assertCount(1, $users);
    }

    public function test_user_near_scope_orders_by_distance(): void
    {
        User::factory()->create([
            'name' => 'Close User',
            'location' => DB::raw('ST_MakePoint(-122.4194, 37.7749)::geography'),
        ]);

        User::factory()->create([
            'name' => 'Far User',
            'location' => DB::raw('ST_MakePoint(0, 0)::geography'),
        ]);

        $users = User::near('location', 37.7749, -122.4194)->get();

        $this->assertEquals('Close User', $users->first()->name);
        $this->assertLessThan($users->last()->distance, $users->first()->distance);
    }
}
