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

class DashboardMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected Layer $layer;

    protected Layer $otherLayer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();

        $admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->organization = Organization::factory()->create(['name' => 'Coastal Ops']);
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->user->roles()->attach($admin);

        Schema::dropIfExists('ops_status_points');
        Schema::dropIfExists('ops_other_points');

        Schema::create('ops_status_points', function ($table) {
            $table->increments('id');
            $table->string('status');
            $table->integer('amount');
            $table->string('title');
            $table->string('notes')->nullable();
        });
        Schema::create('ops_other_points', function ($table) {
            $table->increments('id');
            $table->string('status');
            $table->integer('amount');
            $table->string('title');
        });

        DB::table('ops_status_points')->insert([
            ['status' => 'Open', 'amount' => 10, 'title' => 'Pump A', 'notes' => 'North'],
            ['status' => 'Open', 'amount' => 5, 'title' => 'Pump B', 'notes' => 'South'],
            ['status' => 'Closed', 'amount' => 7, 'title' => 'Pump C', 'notes' => 'East'],
        ]);
        DB::table('ops_other_points')->insert([
            ['status' => 'Open', 'amount' => 100, 'title' => 'Depot'],
        ]);

        $this->layer = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'name' => 'Pumps',
            'table_name' => 'ops_status_points',
            'geometry_type' => 'Point',
            'published' => true,
            'style_config' => [
                'fill_color' => '#112233',
                'stroke_color' => '#abcdef',
                'stroke_width' => 2,
            ],
        ]);
        $this->otherLayer = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'name' => 'Depots',
            'table_name' => 'ops_other_points',
            'geometry_type' => 'Point',
            'published' => true,
        ]);
    }

    public function test_text_only_dashboard_works_without_a_layer_or_form(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboards.store'), [
            'name' => 'Briefing',
            'description' => 'A note for the shift lead',
            'is_public' => 1,
            'widgets' => [[
                'id' => 'note',
                'type' => 'text',
                'title' => 'Shift note',
                'body' => 'Pumps are being checked.',
            ]],
        ]);

        $response->assertRedirect();
        $dashboard = DashboardBoard::where('name', 'Briefing')->firstOrFail();
        $this->assertTrue($dashboard->is_public);
        $this->assertSame('Pumps are being checked.', $dashboard->widgets[0]['body']);
        $this->assertArrayNotHasKey('form_id', $dashboard->widgets[0]);
        $this->assertArrayNotHasKey('layer_id', $dashboard->widgets[0]);

        $this->getJson(route('dashboards.public.data', $dashboard->share_token))
            ->assertOk()
            ->assertJsonPath('widgets.0.status', 'ok')
            ->assertJsonPath('widgets.0.body', 'Pumps are being checked.')
            ->assertJsonPath('widgets.0.error', null);
    }

    public function test_layer_widgets_aggregate_demo_rows(): void
    {
        $dashboard = $this->board($this->monitoringWidgets(), false);

        $response = $this->actingAs($this->user)->getJson(route('dashboards.data', $dashboard));
        $response->assertOk();
        $widgets = $response->json('widgets');

        $count = $this->widget($widgets, 'kpi');
        $this->assertSame('ok', $count['status']);
        $this->assertNull($count['error']);
        $this->assertSame(3, $count['value']);

        $this->assertEquals(22, $this->widget($widgets, 'sum')['value']);
        $this->assertSame('$', $this->widget($widgets, 'sum')['prefix']);
        $this->assertEqualsWithDelta(22 / 3, $this->widget($widgets, 'avg')['value'], 0.001);

        $chart = $this->widget($widgets, 'chart');
        $this->assertSame(['Open', 'Closed'], $chart['labels']);
        $this->assertEquals([2, 1], $chart['values']);
        $this->assertSame('bar', $chart['chart_style']);

        $this->assertSame(['Closed', 'Open'], $this->widget($widgets, 'filter')['options']);
        $this->assertSame(
            ['Pump A', 'Pump B', 'Pump C'],
            array_column($this->widget($widgets, 'list')['rows'], 'title')
        );
        $this->assertCount(3, $this->widget($widgets, 'table')['rows']);
        $this->assertSame('Pumps are staffed overnight.', $this->widget($widgets, 'note')['body']);
        $this->assertSame(1, $this->widget($widgets, 'other')['value']);

        $this->actingAs($this->user)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboards/Show', false)
                ->where('dashboard.name', 'Operations')
                ->has('widgetData'));
    }

    public function test_category_filter_limits_widgets_on_the_same_layer_only(): void
    {
        $dashboard = $this->board($this->monitoringWidgets(), true);

        $filtered = $this->actingAs($this->user)->getJson(
            route('dashboards.data', $dashboard).'?'.http_build_query([
                'filters' => [$this->layer->id => 'Open'],
            ])
        );
        $filtered->assertOk();
        $widgets = $filtered->json('widgets');

        $this->assertSame(2, $this->widget($widgets, 'kpi')['value']);
        $this->assertTrue($this->widget($widgets, 'kpi')['filtered']);
        $this->assertEquals(15, $this->widget($widgets, 'sum')['value']);
        $this->assertSame(['Open'], $this->widget($widgets, 'chart')['labels']);
        $this->assertEquals([2], $this->widget($widgets, 'chart')['values']);
        $this->assertSame(['Open'], $this->widget($widgets, 'pie')['labels']);
        $this->assertSame(['Open', 'Open'], array_column($this->widget($widgets, 'table')['rows'], 'status'));
        $this->assertSame(['Pump A', 'Pump B'], array_column($this->widget($widgets, 'list')['rows'], 'title'));
        $this->assertSame('Open', $this->widget($widgets, 'filter')['value']);
        $this->assertEqualsCanonicalizing(['Closed', 'Open'], $this->widget($widgets, 'filter')['options']);
        $this->assertSame(1, $this->widget($widgets, 'other')['value']);
        $this->assertFalse($this->widget($widgets, 'other')['filtered']);

        $injected = $this->actingAs($this->user)->getJson(
            route('dashboards.data', $dashboard).'?'.http_build_query([
                'filters' => [$this->layer->id => "Open' OR 1=1 --"],
            ])
        );
        $this->assertSame(0, $this->widget($injected->json('widgets'), 'kpi')['value']);

        $unscoped = $this->board([[
            'id' => 'kpi',
            'type' => 'indicator',
            'title' => 'Incidents',
            'layer_id' => $this->layer->id,
            'aggregation' => 'count',
        ]], false);
        $ignored = $this->actingAs($this->user)->getJson(
            route('dashboards.data', $unscoped).'?'.http_build_query([
                'filters' => [$this->layer->id => 'Open'],
            ])
        );
        $this->assertSame(3, $ignored->json('widgets.0.value'));
        $this->assertFalse($ignored->json('widgets.0.filtered'));
    }

    public function test_unlinked_widgets_stay_empty_and_charts_require_a_field(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('dashboards.preview'), [
            'widgets' => [
                ['id' => 'kpi', 'type' => 'indicator', 'title' => 'Incidents'],
                ['id' => 'sum', 'type' => 'indicator', 'title' => 'Exposure', 'layer_id' => $this->layer->id, 'aggregation' => 'sum', 'column' => 'amount); drop'],
                ['id' => 'chart', 'type' => 'serial', 'title' => 'By status', 'layer_id' => $this->layer->id],
                ['id' => 'map', 'type' => 'map', 'title' => 'Map'],
                ['id' => 'note', 'type' => 'text', 'title' => 'Note', 'body' => 'Still valid'],
            ],
        ]);

        $response->assertOk();
        $widgets = $response->json('widgets');

        $this->assertSame('empty', $this->widget($widgets, 'kpi')['status']);
        $this->assertNull($this->widget($widgets, 'kpi')['error']);
        $this->assertSame('empty', $this->widget($widgets, 'sum')['status']);
        $this->assertSame('empty', $this->widget($widgets, 'chart')['status']);
        $this->assertSame([], $this->widget($widgets, 'chart')['rows']);
        $this->assertStringContainsString('group', $this->widget($widgets, 'chart')['message']);
        $this->assertSame('empty', $this->widget($widgets, 'map')['status']);
        $this->assertNull($this->widget($widgets, 'map')['map']);
        $this->assertSame('ok', $this->widget($widgets, 'note')['status']);
        $this->assertSame('Still valid', $this->widget($widgets, 'note')['body']);
    }

    public function test_map_widget_accepts_a_layer_or_a_saved_map(): void
    {
        $saved = Map::create([
            'name' => 'Tripoli operations',
            'description' => 'Shift map',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'imagery',
            'viewport' => ['center' => [13.19, 32.88], 'zoom' => 6, 'rotation' => 0],
            'layers' => [[
                'id' => $this->layer->id,
                'name' => 'Pumps',
                'type' => 'mvt',
                'visible' => true,
            ]],
            'is_public' => false,
        ]);
        $foreignOrg = Organization::factory()->create();
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id]);
        $foreignMap = Map::create([
            'name' => 'Other org map',
            'user_id' => $foreignUser->id,
            'organization_id' => $foreignOrg->id,
            'basemap' => 'osm',
            'layers' => [],
        ]);
        $foreignLayer = Layer::factory()->create([
            'user_id' => $foreignUser->id,
            'organization_id' => $foreignOrg->id,
            'table_name' => 'ops_status_points',
        ]);

        $response = $this->actingAs($this->user)->post(route('dashboards.store'), [
            'name' => 'Map board',
            'is_public' => 0,
            'widgets' => [
                [
                    'id' => 'layer-map',
                    'type' => 'map',
                    'title' => 'Layer',
                    'source' => 'layer',
                    'layer_id' => $this->layer->id,
                    'map_id' => $saved->id,
                    'basemap' => 'osm',
                ],
                [
                    'id' => 'saved-map',
                    'type' => 'map',
                    'title' => 'Saved',
                    'source' => 'map',
                    'map_id' => $saved->id,
                ],
                [
                    'id' => 'foreign-map',
                    'type' => 'map',
                    'title' => 'Foreign',
                    'source' => 'map',
                    'map_id' => $foreignMap->id,
                ],
                [
                    'id' => 'foreign-layer',
                    'type' => 'indicator',
                    'title' => 'Foreign count',
                    'layer_id' => $foreignLayer->id,
                ],
            ],
        ]);
        $response->assertRedirect();

        $dashboard = DashboardBoard::where('name', 'Map board')->firstOrFail();
        $this->assertSame('map', $dashboard->widgets[1]['source']);
        $this->assertSame($saved->id, $dashboard->widgets[1]['map_id']);

        $data = $this->actingAs($this->user)
            ->getJson(route('dashboards.data', $dashboard))
            ->assertOk()
            ->json('widgets');

        $layerMap = $this->widget($data, 'layer-map');
        $this->assertSame('layer', $layerMap['source']);
        $this->assertSame('ok', $layerMap['status']);
        $this->assertSame($this->layer->id, $layerMap['map']['layers'][0]['id']);
        $this->assertStringContainsString("/api/layers/{$this->layer->id}/tiles/", $layerMap['map']['layers'][0]['mvtUrl']);
        $this->assertSame('#112233', $layerMap['map']['layers'][0]['style_config']['fill_color']);

        $savedMap = $this->widget($data, 'saved-map');
        $this->assertSame('map', $savedMap['source']);
        $this->assertSame('ok', $savedMap['status']);
        $this->assertSame('Tripoli operations', $savedMap['map']['name']);
        $this->assertSame('imagery', $savedMap['map']['basemap']);
        $this->assertSame(6, $savedMap['map']['viewport']['zoom']);
        $this->assertStringContainsString("/api/layers/{$this->layer->id}/tiles/", $savedMap['map']['layers'][0]['mvtUrl']);

        $this->assertSame('error', $this->widget($data, 'foreign-map')['status']);
        $this->assertSame('Saved map not found.', $this->widget($data, 'foreign-map')['error']);
        $this->assertSame('error', $this->widget($data, 'foreign-layer')['status']);
        $this->assertSame('Layer or table not found.', $this->widget($data, 'foreign-layer')['error']);
    }

    public function test_public_dashboard_omits_an_unconfigured_map_and_serves_guests(): void
    {
        $saved = Map::create([
            'name' => 'Tripoli operations',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'viewport' => ['center' => [13.19, 32.88], 'zoom' => 8],
            'layers' => [],
            'is_public' => false,
        ]);
        $dashboard = $this->board(array_merge($this->monitoringWidgets(), [
            ['id' => 'blank-map', 'type' => 'map', 'title' => 'Optional map', 'source' => 'layer'],
            ['id' => 'saved-map', 'type' => 'map', 'title' => 'Saved', 'source' => 'map', 'map_id' => $saved->id],
        ]), true);

        $guest = $this->getJson(
            route('dashboards.public.data', $dashboard->share_token).'?'.http_build_query([
                'filters' => [$this->layer->id => 'Closed'],
            ])
        );
        $guest->assertOk();
        $ids = array_column($guest->json('widgets'), 'id');
        $this->assertNotContains('blank-map', $ids);
        $this->assertContains('saved-map', $ids);
        $this->assertContains('note', $ids);
        $this->assertSame(1, $this->widget($guest->json('widgets'), 'kpi')['value']);
        $this->assertSame('Tripoli operations', $this->widget($guest->json('widgets'), 'saved-map')['map']['name']);

        $this->get(route('dashboards.public', $dashboard->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboards/Public', false)
                ->where('dashboard.name', 'Operations')
                ->where('organizationName', 'Coastal Ops')
                ->where('dashboard.description', 'Night shift monitoring')
                ->missing('layers'));

        $private = $this->board([[
            'id' => 'note',
            'type' => 'text',
            'title' => 'Private note',
            'body' => 'Internal',
        ]], false);
        $this->get(route('dashboards.public', $private->share_token))->assertNotFound();
        $this->getJson(route('dashboards.public.data', $private->share_token))->assertNotFound();
        $this->getJson(route('dashboards.data', $dashboard))->assertUnauthorized();

        $this->actingAs($this->user)
            ->getJson(route('dashboards.data', $dashboard))
            ->assertOk()
            ->assertJsonFragment(['id' => 'blank-map', 'status' => 'empty']);
    }

    public function test_editor_lists_saved_maps_without_requiring_one(): void
    {
        Map::create([
            'name' => 'Tripoli operations',
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'basemap' => 'osm',
            'layers' => [],
        ]);

        $this->actingAs($this->user)
            ->get(route('dashboards.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboards/Editor', false)
                ->has('maps', 1)
                ->where('maps.0.name', 'Tripoli operations')
                ->where('catalog.5.type', 'map')
                ->where('catalog.5.description', 'Layer or a saved map'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     */
    protected function board(array $widgets, bool $public): DashboardBoard
    {
        return DashboardBoard::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'Operations',
            'description' => 'Night shift monitoring',
            'widgets' => $widgets,
            'is_public' => $public,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function monitoringWidgets(): array
    {
        $layerId = $this->layer->id;

        return [
            ['id' => 'kpi', 'type' => 'indicator', 'title' => 'Incidents', 'layer_id' => $layerId, 'aggregation' => 'count'],
            ['id' => 'sum', 'type' => 'indicator', 'title' => 'Exposure', 'layer_id' => $layerId, 'aggregation' => 'sum', 'column' => 'amount', 'prefix' => '$'],
            ['id' => 'avg', 'type' => 'indicator', 'title' => 'Average', 'layer_id' => $layerId, 'aggregation' => 'avg', 'column' => 'amount'],
            ['id' => 'chart', 'type' => 'serial', 'title' => 'By status', 'layer_id' => $layerId, 'group_by' => 'status', 'aggregation' => 'count', 'chart_style' => 'bar'],
            ['id' => 'pie', 'type' => 'pie', 'title' => 'Share', 'layer_id' => $layerId, 'group_by' => 'status', 'aggregation' => 'count'],
            ['id' => 'table', 'type' => 'table', 'title' => 'Rows', 'layer_id' => $layerId, 'columns' => ['title', 'status', 'amount'], 'limit' => 20],
            ['id' => 'list', 'type' => 'list', 'title' => 'List', 'layer_id' => $layerId, 'title_field' => 'title', 'description_field' => 'notes', 'limit' => 20],
            ['id' => 'filter', 'type' => 'category', 'title' => 'Status', 'layer_id' => $layerId, 'group_by' => 'status'],
            ['id' => 'note', 'type' => 'text', 'title' => 'Note', 'body' => 'Pumps are staffed overnight.'],
            ['id' => 'other', 'type' => 'indicator', 'title' => 'Depots', 'layer_id' => $this->otherLayer->id, 'aggregation' => 'count'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<string, mixed>
     */
    protected function widget(array $widgets, string $id): array
    {
        foreach ($widgets as $widget) {
            if (($widget['id'] ?? null) === $id) {
                return $widget;
            }
        }

        $this->fail("Missing widget [{$id}].");
    }
}
