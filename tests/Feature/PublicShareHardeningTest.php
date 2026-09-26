<?php

namespace Tests\Feature;

use App\Models\DashboardBoard;
use App\Models\Layer;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublicShareHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected Layer $pumps;

    protected Layer $berths;

    protected function setUp(): void
    {
        parent::setUp();

        $editor = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $this->organization = Organization::factory()->create(['name' => 'Harbor Ops']);
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->user->roles()->attach($editor);

        Schema::dropIfExists('share_points');
        Schema::create('share_points', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        $this->pumps = $this->layer('Pumps');
        $this->berths = $this->layer('Berths');
    }

    public function test_signed_out_guest_receives_only_that_public_maps_tiles(): void
    {
        $foreign = $this->foreignLayer('Other org cables');
        $map = $this->map('Harbor survey', true, [
            [
                'id' => $this->berths->id,
                'name' => 'Berths',
                'type' => 'mvt',
                'table_name' => 'secret_berth_table',
                'mvtUrl' => '/api/layers/'.$this->berths->id.'/tiles/{z}/{x}/{y}.mvt',
            ],
            [
                'id' => $foreign->id,
                'name' => 'Other org cables',
                'type' => 'mvt',
                'mvtUrl' => '/api/layers/'.$foreign->id.'/tiles/{z}/{x}/{y}.mvt',
            ],
        ]);
        $other = $this->map('Private operations', false, [
            ['id' => $this->pumps->id, 'name' => 'Pumps', 'type' => 'mvt'],
        ]);

        $this->get(route('maps.shared', $map->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Maps/Shared', false)
                ->where('map.name', 'Harbor survey')
                ->where('map.share_token', $map->share_token)
                ->missing('map.user_id')
                ->missing('map.organization_id')
                ->has('map.layers', 1)
                ->where('map.layers.0.id', $this->berths->id)
                ->where('map.layers.0.name', 'Berths')
                ->missing('map.layers.0.table_name')
                ->where(
                    'map.layers.0.mvtUrl',
                    url('/maps/shared/'.$map->share_token.'/tiles/'.$this->berths->id.'/{z}/{x}/{y}.mvt')
                ));

        $this->expectTile();

        $this->get(route('maps.shared.tiles', [
            'token' => $map->share_token,
            'layer' => $this->berths->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');

        $this->get(route('maps.shared.tiles', [
            'token' => $map->share_token,
            'layer' => $this->pumps->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $this->get(route('maps.shared.tiles', [
            'token' => $map->share_token,
            'layer' => $foreign->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $this->get(route('maps.shared.tiles', [
            'token' => $other->share_token,
            'layer' => $this->pumps->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $this->get(route('maps.shared', $other->share_token))->assertNotFound();

        $this->get('/api/layers/'.$this->berths->id.'/tiles/1/0/0.mvt')
            ->assertUnauthorized();
    }

    public function test_public_dashboard_hides_private_maps_and_serves_allowed_tiles(): void
    {
        $foreignOrg = Organization::factory()->create();
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id]);
        $foreignMap = Map::create([
            'name' => 'Other org survey',
            'user_id' => $foreignUser->id,
            'organization_id' => $foreignOrg->id,
            'basemap' => 'osm',
            'layers' => [],
            'is_public' => true,
        ]);
        $classified = $this->layer('Classified cables');
        $private = $this->map('Private operations', false, [
            ['id' => $classified->id, 'name' => 'Classified cables', 'type' => 'mvt'],
        ]);
        $public = $this->map('Harbor survey', true, [
            ['id' => $this->berths->id, 'name' => 'Berths', 'type' => 'mvt'],
        ]);

        $dashboard = DashboardBoard::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'Night board',
            'description' => 'Public shift view',
            'is_public' => true,
            'widgets' => [
                ['id' => 'note', 'type' => 'text', 'title' => 'Shift', 'body' => 'Berths are open.'],
                ['id' => 'private-map', 'type' => 'map', 'title' => 'Private', 'source' => 'map', 'map_id' => $private->id],
                ['id' => 'public-map', 'type' => 'map', 'title' => 'Harbor', 'source' => 'map', 'map_id' => $public->id],
                ['id' => 'foreign-map', 'type' => 'map', 'title' => 'Foreign', 'source' => 'map', 'map_id' => $foreignMap->id],
                ['id' => 'layer-map', 'type' => 'map', 'title' => 'Pumps', 'source' => 'layer', 'layer_id' => $this->pumps->id],
            ],
        ]);

        $guest = $this->getJson(route('dashboards.public.data', $dashboard->share_token));
        $guest->assertOk();
        $payload = json_encode($guest->json('widgets'), JSON_UNESCAPED_SLASHES);
        $ids = array_column($guest->json('widgets'), 'id');

        $this->assertContains('note', $ids);
        $this->assertContains('public-map', $ids);
        $this->assertContains('layer-map', $ids);
        $this->assertNotContains('private-map', $ids);
        $this->assertNotContains('foreign-map', $ids);
        $this->assertStringNotContainsString('Private operations', $payload);
        $this->assertStringNotContainsString('Classified cables', $payload);
        $this->assertStringNotContainsString('Other org survey', $payload);
        $this->assertStringNotContainsString($private->share_token, $payload);
        $this->assertNotContains($private->id, $this->referencedMapIds($guest->json('widgets')));
        $this->assertNotContains($foreignMap->id, $this->referencedMapIds($guest->json('widgets')));
        $this->assertStringContainsString('Harbor survey', $payload);
        $this->assertStringContainsString('/maps/shared/'.$public->share_token.'/tiles/'.$this->berths->id.'/', $payload);
        $this->assertStringContainsString('/dashboards/shared/'.$dashboard->share_token.'/tiles/'.$this->pumps->id.'/', $payload);
        $this->assertStringNotContainsString('/api/layers/', $payload);

        $this->get(route('dashboards.public', $dashboard->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboards/Public', false)
                ->where('dashboard.widgets', function ($widgets) use ($private, $foreignMap) {
                    $encoded = json_encode($widgets);

                    return is_string($encoded)
                        && ! str_contains($encoded, 'Private operations')
                        && ! str_contains($encoded, 'Classified cables')
                        && ! str_contains($encoded, 'Other org survey')
                        && ! str_contains($encoded, $private->share_token)
                        && ! in_array($private->id, $this->referencedMapIds($widgets), false)
                        && ! in_array($foreignMap->id, $this->referencedMapIds($widgets), false);
                })
                ->where('widgetData', function ($data) use ($private) {
                    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);

                    return is_string($encoded)
                        && str_contains($encoded, 'Harbor survey')
                        && ! str_contains($encoded, 'Private operations')
                        && ! str_contains($encoded, $private->share_token)
                        && ! str_contains($encoded, '/api/layers/');
                }));

        $this->expectTile(2);

        $this->get(route('dashboards.shared.tiles', [
            'token' => $dashboard->share_token,
            'layer' => $this->pumps->id,
            'z' => 2,
            'x' => 1,
            'y' => 1,
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');

        $this->get(route('maps.shared.tiles', [
            'token' => $public->share_token,
            'layer' => $this->berths->id,
            'z' => 2,
            'x' => 1,
            'y' => 1,
        ]))->assertOk();

        $this->get(route('dashboards.shared.tiles', [
            'token' => $dashboard->share_token,
            'layer' => $this->berths->id,
            'z' => 2,
            'x' => 1,
            'y' => 1,
        ]))->assertNotFound();

        $this->get(route('dashboards.shared.tiles', [
            'token' => $dashboard->share_token,
            'layer' => $classified->id,
            'z' => 2,
            'x' => 1,
            'y' => 1,
        ]))->assertNotFound();

        $this->get(route('maps.shared.tiles', [
            'token' => $private->share_token,
            'layer' => $classified->id,
            'z' => 2,
            'x' => 1,
            'y' => 1,
        ]))->assertNotFound();

        $this->get(route('maps.shared.tiles', [
            'token' => $public->share_token,
            'layer' => $this->pumps->id,
            'z' => 2,
            'x' => 1,
            'y' => 1,
        ]))->assertNotFound();

        $privateBoard = DashboardBoard::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'Internal board',
            'is_public' => false,
            'widgets' => [
                ['id' => 'layer-map', 'type' => 'map', 'title' => 'Pumps', 'source' => 'layer', 'layer_id' => $this->pumps->id],
            ],
        ]);

        $this->get(route('dashboards.shared.tiles', [
            'token' => $privateBoard->share_token,
            'layer' => $this->pumps->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $this->get('/api/layers/'.$this->pumps->id.'/tiles/1/0/0.mvt')
            ->assertUnauthorized();

        $this->actingAs($this->user)
            ->getJson(route('dashboards.data', $dashboard))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Private operations'])
            ->assertJsonFragment(['name' => 'Classified cables']);
    }

    public function test_share_token_from_another_organization_cannot_read_local_layers(): void
    {
        $foreignOrg = Organization::factory()->create();
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id]);
        $foreignLayer = Layer::factory()->create([
            'name' => 'Other org cables',
            'user_id' => $foreignUser->id,
            'organization_id' => $foreignOrg->id,
            'table_name' => 'share_points',
            'geometry_type' => 'Point',
        ]);
        $foreignMap = Map::create([
            'name' => 'Other org survey',
            'user_id' => $foreignUser->id,
            'organization_id' => $foreignOrg->id,
            'basemap' => 'osm',
            'is_public' => true,
            'layers' => [
                ['id' => $this->pumps->id, 'name' => 'Pumps', 'type' => 'mvt'],
                ['id' => $foreignLayer->id, 'name' => 'Other org cables', 'type' => 'mvt'],
            ],
        ]);
        $foreignDashboard = DashboardBoard::create([
            'organization_id' => $foreignOrg->id,
            'user_id' => $foreignUser->id,
            'name' => 'Other board',
            'is_public' => true,
            'widgets' => [
                ['id' => 'layer-map', 'type' => 'map', 'title' => 'Pumps', 'source' => 'layer', 'layer_id' => $this->pumps->id],
            ],
        ]);

        $this->expectTile();

        $this->get(route('maps.shared.tiles', [
            'token' => $foreignMap->share_token,
            'layer' => $this->pumps->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();

        $this->get(route('maps.shared.tiles', [
            'token' => $foreignMap->share_token,
            'layer' => $foreignLayer->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertOk();

        $this->get(route('dashboards.shared.tiles', [
            'token' => $foreignDashboard->share_token,
            'layer' => $this->pumps->id,
            'z' => 1,
            'x' => 0,
            'y' => 0,
        ]))->assertNotFound();
    }

    protected function layer(string $name): Layer
    {
        return Layer::factory()->create([
            'name' => $name,
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'table_name' => 'share_points',
            'geometry_type' => 'Point',
            'published' => true,
        ]);
    }

    protected function foreignLayer(string $name): Layer
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        return Layer::factory()->create([
            'name' => $name,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'table_name' => 'share_points',
            'geometry_type' => 'Point',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $layers
     */
    protected function map(string $name, bool $public, array $layers): Map
    {
        return Map::create([
            'name' => $name,
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'viewport' => ['center' => [13.19, 32.88], 'zoom' => 8, 'rotation' => 0],
            'layers' => $layers,
            'is_public' => $public,
        ]);
    }

    protected function expectTile(int $times = 1): void
    {
        $features = Mockery::mock(FeatureService::class);
        $features->shouldReceive('tile')->times($times)->andReturn('mvt-bytes');
        $this->app->instance(FeatureService::class, $features);
    }

    /**
     * @return array<int, int>
     */
    protected function referencedMapIds(mixed $widgets): array
    {
        $ids = [];
        foreach (collect($widgets) as $widget) {
            if (! is_array($widget)) {
                continue;
            }
            if (isset($widget['map_id']) && $widget['map_id'] !== null) {
                $ids[] = (int) $widget['map_id'];
            }
            if (isset($widget['map']['id'])) {
                $ids[] = (int) $widget['map']['id'];
            }
        }

        return $ids;
    }
}
