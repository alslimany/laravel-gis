<?php

namespace Tests\Feature;

use App\Helpers\GeometryColumnHelper;
use App\Models\Layer;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);

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

    public function test_geojson_and_csv_export_after_geojson_import_with_geom_column(): void
    {
        $layer = $this->layerBackedByImportedTable('geom');

        $this->assertLayerGeoJsonAndCsvExport($layer);
    }

    public function test_geojson_and_csv_export_when_the_column_is_named_geometry(): void
    {
        $layer = $this->layerBackedByImportedTable('geometry');

        $this->assertLayerGeoJsonAndCsvExport($layer);
    }

    /**
     * GeoJSON import (ogr2ogr GEOMETRY_NAME=geom) and older tables that use
     * "geometry" both have to export. SQLite stands in for PostGIS with the
     * same column name and ST_AsGeoJSON / ST_AsText results.
     */
    protected function layerBackedByImportedTable(string $geometryColumn): Layer
    {
        if (! in_array($geometryColumn, ['geom', 'geometry'], true)) {
            throw new \InvalidArgumentException($geometryColumn);
        }

        $table = 'import_org'.$this->organization->id.'_harbor_'.$geometryColumn;
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            try {
                DB::selectOne('SELECT PostGIS_Version()');
            } catch (\Throwable) {
                $this->markTestSkipped('PostGIS extension not available.');
            }

            DB::statement("DROP TABLE IF EXISTS {$table}");
            DB::statement("
                CREATE TABLE {$table} (
                    id serial PRIMARY KEY,
                    name text,
                    {$geometryColumn} geometry(Point, 4326)
                )
            ");
            DB::table($table)->insert([
                'name' => 'Harbor',
                $geometryColumn => DB::raw('ST_SetSRID(ST_MakePoint(12.5, 41.9), 4326)'),
            ]);
        } elseif ($driver === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->sqliteCreateFunction('ST_AsGeoJSON', static fn ($value) => $value, 1);
            $pdo->sqliteCreateFunction('ST_AsText', static fn ($value) => $value, 1);

            DB::statement("DROP TABLE IF EXISTS {$table}");
            DB::statement("
                CREATE TABLE {$table} (
                    id integer PRIMARY KEY,
                    name text,
                    {$geometryColumn} text
                )
            ");
            DB::table($table)->insert([
                'id' => 1,
                'name' => 'Harbor',
                $geometryColumn => '{"type":"Point","coordinates":[12.5,41.9]}',
            ]);
        } else {
            $this->markTestSkipped('Export column test needs SQLite or PostGIS.');
        }

        GeometryColumnHelper::forget($table);

        return Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'project_id' => $this->layer->project_id,
            'name' => 'Harbor',
            'table_name' => $table,
            'geometry_type' => 'Point',
            'feature_count' => 1,
            'metadata' => ['source' => 'geojson'],
        ]);
    }

    protected function assertLayerGeoJsonAndCsvExport(Layer $layer): void
    {
        $geojson = $this->actingAs($this->user)
            ->get(route('export.layer.geojson', $layer));

        $geojson->assertOk();
        $this->assertStringStartsWith('application/geo+json', (string) $geojson->headers->get('Content-Type'));

        $geometry = $geojson->json('features.0.geometry');
        $properties = $geojson->json('features.0.properties');
        $this->assertSame('Point', $geometry['type']);
        $this->assertEqualsWithDelta(12.5, $geometry['coordinates'][0], 0.00001);
        $this->assertEqualsWithDelta(41.9, $geometry['coordinates'][1], 0.00001);
        $this->assertSame('Harbor', $properties['name']);
        $this->assertArrayNotHasKey('geom', $properties);
        $this->assertArrayNotHasKey('geometry', $properties);
        $this->assertArrayNotHasKey('geojson', $properties);

        $csv = $this->actingAs($this->user)
            ->get(route('export.layer.csv', $layer));

        $csv->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));

        $rows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($csv->getContent())));
        $headers = $rows[0];
        $record = array_combine($headers, $rows[1]);

        $this->assertContains('name', $headers);
        $this->assertContains('wkt', $headers);
        $this->assertNotContains('geom', $headers);
        $this->assertNotContains('geometry', $headers);
        $this->assertSame('Harbor', $record['name']);
        $this->assertNotSame('', $record['wkt']);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertStringContainsString('POINT', $record['wkt']);
        } else {
            $this->assertStringContainsString('Point', $record['wkt']);
        }
    }
}
