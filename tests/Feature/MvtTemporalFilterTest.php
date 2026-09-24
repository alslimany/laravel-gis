<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MvtTemporalFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostGIS required for MVT temporal filter tests.');
        }

        try {
            DB::selectOne('SELECT PostGIS_Version()');
        } catch (\Throwable) {
            $this->markTestSkipped('PostGIS extension not available.');
        }

        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $this->user->roles()->attach($editorRole);

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $table = 'layer_mvt_time_'.uniqid();
        DB::statement("
            CREATE TABLE {$table} (
                id serial PRIMARY KEY,
                name text,
                observed_at date,
                geom geometry(Point, 4326)
            )
        ");
        DB::statement("CREATE INDEX ON {$table} USING GIST (geom)");
        DB::table($table)->insert([
            [
                'name' => 'inside',
                'observed_at' => '2024-06-15',
                'geom' => DB::raw("ST_SetSRID(ST_MakePoint(0.1, 0.1), 4326)"),
            ],
            [
                'name' => 'outside',
                'observed_at' => '2020-01-01',
                'geom' => DB::raw("ST_SetSRID(ST_MakePoint(0.1, 0.1), 4326)"),
            ],
        ]);

        $this->layer = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'table_name' => $table,
            'geometry_type' => 'Point',
            'metadata' => ['time_field' => 'observed_at'],
        ]);
    }

    public function test_tile_accepts_snake_case_time_params_and_filters(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/api/layers/{$this->layer->id}/tiles/0/0/0.mvt?time_field=observed_at&time_from=2024-01-01&time_to=2024-12-31");

        $response->assertOk();
        $this->assertSame('application/vnd.mapbox-vector-tile', $response->headers->get('Content-Type'));
        $this->assertNotSame('', $response->getContent());
    }

    public function test_tile_accepts_camel_case_time_params(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/api/layers/{$this->layer->id}/tiles/0/0/0.mvt?timeField=observed_at&timeFrom=2024-01-01&timeTo=2024-12-31");

        $response->assertOk();
        $this->assertNotSame('', $response->getContent());
    }

    public function test_tile_time_window_excludes_features_outside_range(): void
    {
        $service = app(FeatureService::class);

        $withWindow = $service->tile($this->layer, 0, 0, 0, 'observed_at', '2024-01-01', '2024-12-31');
        $emptyWindow = $service->tile($this->layer, 0, 0, 0, 'observed_at', '2010-01-01', '2010-12-31');

        $this->assertNotSame('', $withWindow);
        $this->assertSame('', $emptyWindow);
    }
}
