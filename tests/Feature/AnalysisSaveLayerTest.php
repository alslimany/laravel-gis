<?php

namespace Tests\Feature;

use App\Jobs\PublishLayerToGeoServer;
use App\Models\AnalysisResult;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalysisSaveLayerTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $editor;

    protected User $viewer;

    protected Layer $source;

    protected AnalysisResult $analysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $editorRole = Role::firstOrCreate(['name' => 'editor'], ['description' => 'Editor']);
        $viewerRole = Role::firstOrCreate(['name' => 'viewer'], ['description' => 'Viewer']);
        Role::firstOrCreate(['name' => 'admin'], ['description' => 'Administrator']);

        $this->organization = Organization::factory()->create();
        $this->editor = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->editor->roles()->attach($editorRole);
        $this->viewer = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->viewer->roles()->attach($viewerRole);

        Schema::dropIfExists('analysis_src_points');
        Schema::create('analysis_src_points', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('status');
            $table->text('geom')->nullable();
        });

        DB::table('analysis_src_points')->insert([
            ['id' => 1, 'name' => 'Pump A', 'status' => 'Open', 'geom' => 'POINT(1 2)'],
            ['id' => 2, 'name' => 'Pump B', 'status' => 'Closed', 'geom' => 'POINT(3 4)'],
            ['id' => 3, 'name' => 'Pump C', 'status' => 'Open', 'geom' => 'POINT(5 6)'],
        ]);

        $this->source = Layer::factory()->create([
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'name' => 'Pumps',
            'table_name' => 'analysis_src_points',
            'geometry_type' => 'Point',
            'style_config' => ['fill_color' => '#123456', 'stroke_color' => '#654321'],
        ]);

        LayerField::create([
            'layer_id' => $this->source->id,
            'name' => 'status',
            'alias' => 'Status',
            'type' => 'string',
            'sort_order' => 0,
        ]);

        $this->analysis = AnalysisResult::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->editor->id,
            'layer_id' => $this->source->id,
            'name' => 'Open pumps',
            'kind' => 'attribute_query',
            'feature_count' => 2,
            'feature_ids' => [1, 3],
        ]);
    }

    public function test_editor_saves_analysis_result_as_a_layer(): void
    {
        $response = $this->actingAs($this->editor)->postJson(
            route('api.analysis.results.save-as-layer', $this->analysis),
            []
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('layer.feature_count', 2)
            ->assertJsonPath('layer.published', false);

        $layer = Layer::findOrFail($response->json('layer.id'));
        $this->assertSame($this->organization->id, $layer->organization_id);
        $this->assertSame('Open pumps (result)', $layer->name);
        $this->assertSame($this->source->geometry_type, $layer->geometry_type);
        $this->assertSame($this->source->style_config, $layer->style_config);
        $this->assertNotSame($this->source->table_name, $layer->table_name);
        $this->assertTrue(Schema::hasTable($layer->table_name));
        $this->assertSame(
            ['analysis_result_id' => $this->analysis->id, 'source_layer_id' => $this->source->id],
            $layer->metadata
        );

        $rows = DB::table($layer->table_name)->orderBy('id')->get();
        $this->assertSame([1, 3], $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['Pump A', 'Pump C'], $rows->pluck('name')->all());
        $this->assertSame(['POINT(1 2)', 'POINT(5 6)'], $rows->pluck('geom')->all());
        $this->assertSame(
            [$this->analysis->id, $this->analysis->id],
            $rows->pluck('analysis_result_id')->map(fn ($id) => (int) $id)->all()
        );

        $this->assertSame($layer->id, $this->analysis->fresh()->summary['resulting_layer_id']);

        $fields = $layer->fields()->get();
        $this->assertCount(1, $fields);
        $this->assertSame('status', $fields[0]->name);
        $this->assertSame('Status', $fields[0]->alias);
    }

    public function test_saving_twice_gets_a_unique_layer_name(): void
    {
        $first = $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertCreated();

        $second = $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertCreated();

        $this->assertSame('Open pumps (result)', $first->json('layer.name'));
        $this->assertSame('Open pumps (result) (2)', $second->json('layer.name'));

        $firstLayer = Layer::findOrFail($first->json('layer.id'));
        $secondLayer = Layer::findOrFail($second->json('layer.id'));
        $this->assertNotSame($firstLayer->table_name, $secondLayer->table_name);

        // The back-reference now points at the newest result layer.
        $this->assertSame($secondLayer->id, $this->analysis->fresh()->summary['resulting_layer_id']);
    }

    public function test_result_layer_can_receive_new_features(): void
    {
        $response = $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertCreated();

        $layer = Layer::findOrFail($response->json('layer.id'));

        $created = app(FeatureService::class)->create($layer, [
            'name' => 'Pump D',
            'status' => 'Open',
        ], 'POINT(7 8)');

        $this->assertSame('Pump D', $created['properties']['name']);
        $row = DB::table($layer->table_name)->where('name', 'Pump D')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->analysis_result_id);
        $this->assertSame(3, DB::table($layer->table_name)->count());
        $this->assertSame(3, $layer->fresh()->feature_count);
    }

    public function test_cannot_save_a_foreign_organizations_result(): void
    {
        $foreignOrg = Organization::factory()->create();
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id]);
        $foreignUser->roles()->attach(Role::where('name', 'editor')->first());

        $foreign = AnalysisResult::create([
            'organization_id' => $foreignOrg->id,
            'user_id' => $foreignUser->id,
            'layer_id' => $this->source->id,
            'name' => 'Foreign',
            'kind' => 'query',
            'feature_count' => 1,
            'feature_ids' => [1],
        ]);

        $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $foreign), [])
            ->assertNotFound();
    }

    public function test_viewer_cannot_save_a_result_as_a_layer(): void
    {
        $this->actingAs($this->viewer)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertForbidden();
    }

    public function test_guest_is_redirected(): void
    {
        $this->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertUnauthorized();
    }

    public function test_result_without_features_is_rejected(): void
    {
        $empty = AnalysisResult::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->editor->id,
            'layer_id' => $this->source->id,
            'name' => 'Nothing',
            'kind' => 'query',
            'feature_count' => 0,
            'feature_ids' => null,
        ]);

        $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $empty), [])
            ->assertStatus(422);
    }

    public function test_result_whose_features_no_longer_exist_is_rejected(): void
    {
        $stale = AnalysisResult::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->editor->id,
            'layer_id' => $this->source->id,
            'name' => 'Stale',
            'kind' => 'query',
            'feature_count' => 1,
            'feature_ids' => [999],
        ]);

        $before = Layer::count();

        $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $stale), [])
            ->assertStatus(422);

        $this->assertSame($before, Layer::count());
    }

    public function test_publish_flag_publishes_the_new_layer(): void
    {
        Bus::fake([PublishLayerToGeoServer::class]);

        $response = $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [
                'name' => 'Open pumps layer',
                'publish' => 1,
            ])
            ->assertCreated();

        $this->assertSame('Open pumps layer', $response->json('layer.name'));
        Bus::assertDispatched(PublishLayerToGeoServer::class, 1);

        $layer = Layer::findOrFail($response->json('layer.id'));
        $this->assertTrue($layer->fresh()->isPublished());
        $this->assertTrue($response->json('layer.published'));
    }

    public function test_resulting_layer_id_is_exposed_in_the_dashboard_catalog(): void
    {
        $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertCreated();

        $this->actingAs($this->editor)
            ->get(route('dashboards.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboards/Editor')
                ->has('analyses', 1)
                ->where('analyses.0.name', 'Open pumps')
                ->where('analyses.0.resulting_layer_id', $this->analysis->fresh()->summary['resulting_layer_id']));
    }

    public function test_custom_name_replaces_the_default_result_name(): void
    {
        $response = $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [
                'name' => 'Overnight failures',
            ])
            ->assertCreated();

        $this->assertSame('Overnight failures', $response->json('layer.name'));
    }

    public function test_result_layer_show_page_points_back_to_the_source(): void
    {
        $layerId = $this->actingAs($this->editor)
            ->postJson(route('api.analysis.results.save-as-layer', $this->analysis), [])
            ->assertCreated()
            ->json('layer.id');

        $this->actingAs($this->editor)
            ->get(route('layers.show', $layerId))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Layers/Show')
                ->where('origin.kind', 'analysis')
                ->where('origin.label', 'Open pumps')
                ->where('origin.href', '/layers/'.$this->source->id));
    }

    public function test_untouched_layer_has_no_origin_provenance(): void
    {
        $this->actingAs($this->editor)
            ->get(route('layers.show', $this->source))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Layers/Show')
                ->where('origin', null));
    }
}
