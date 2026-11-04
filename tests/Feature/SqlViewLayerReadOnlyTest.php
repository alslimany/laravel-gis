<?php

namespace Tests\Feature;

use App\Exceptions\ReadOnlyLayerException;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureService;
use App\Support\LayerTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Layers backed by a SQL view (metadata.sql_view = true) are read-only.
 */
class SqlViewLayerReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->organization = Organization::factory()->create();
        $this->editor = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->editor->roles()->attach($editorRole);
    }

    protected function sqlViewLayer(): Layer
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'project_id' => null,
            'geometry_type' => null,
            'table_name' => 'ro_layer_'.uniqid(),
            'metadata' => ['sql_view' => true],
        ]);
        Schema::create($layer->table_name, function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        return $layer;
    }

    public function test_feature_service_refuses_writes_on_sql_view_layers(): void
    {
        $layer = $this->sqlViewLayer();
        $id = DB::table($layer->table_name)->insertGetId(['name' => 'x']);
        $service = app(FeatureService::class);

        foreach ([
            fn () => $service->create($layer, ['name' => 'y']),
            fn () => $service->update($layer, $id, ['name' => 'y']),
            fn () => $service->delete($layer, $id),
            fn () => $service->deleteMany($layer, [$id]),
            fn () => $service->deleteByShape($layer, 'Point'),
        ] as $write) {
            try {
                $write();
                $this->fail('Expected ReadOnlyLayerException');
            } catch (ReadOnlyLayerException $e) {
                $this->assertSame(ReadOnlyLayerException::MESSAGE, $e->getMessage());
            }
        }

        $this->assertSame('x', DB::table($layer->table_name)->value('name'));
        $this->assertSame(1, DB::table($layer->table_name)->count());
    }

    public function test_api_feature_writes_on_sql_view_layer_return_422_in_arabic(): void
    {
        $layer = $this->sqlViewLayer();
        $id = DB::table($layer->table_name)->insertGetId(['name' => 'x']);
        Sanctum::actingAs($this->editor, ['*']);

        $this->postJson("/api/v1/layers/{$layer->public_id}/features", ['attributes' => ['name' => 'y'], 'wkt' => 'POINT(13 32)'])
            ->assertStatus(422)->assertJsonPath('error', ReadOnlyLayerException::MESSAGE);
        $this->putJson("/api/v1/layers/{$layer->public_id}/features/{$id}", ['attributes' => ['name' => 'y']])
            ->assertStatus(422)->assertJsonPath('error', ReadOnlyLayerException::MESSAGE);
        $this->deleteJson("/api/v1/layers/{$layer->public_id}/features/{$id}")
            ->assertStatus(422)->assertJsonPath('error', ReadOnlyLayerException::MESSAGE);

        $this->assertSame('x', DB::table($layer->table_name)->value('name'));
    }

    public function test_session_feature_writes_on_sql_view_layer_return_422(): void
    {
        $layer = $this->sqlViewLayer();
        $id = DB::table($layer->table_name)->insertGetId(['name' => 'x']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/features', ['attributes' => ['name' => 'y'], 'wkt' => 'POINT(13 32)'])
            ->assertStatus(422)->assertJsonPath('error', ReadOnlyLayerException::MESSAGE);

        $this->actingAs($this->editor)
            ->from('/layers/'.$layer->id)
            ->delete('/layers/'.$layer->id.'/features/'.$id)
            ->assertSessionHasErrors('layer');

        $this->assertSame(1, DB::table($layer->table_name)->count());
    }

    public function test_field_alter_actions_are_refused_on_sql_view_layers(): void
    {
        $layer = $this->sqlViewLayer();
        $field = LayerField::query()->create(['layer_id' => $layer->id, 'name' => 'name', 'type' => 'string']);
        $this->actingAs($this->editor);

        $this->postJson(route('api.layers.fields.store', $layer), ['name' => 'added_col', 'type' => 'string'])
            ->assertStatus(422)->assertJsonPath('error', ReadOnlyLayerException::MESSAGE);
        $this->putJson(route('api.layers.fields.update', [$layer, $field]), ['alias' => 'X'])
            ->assertStatus(422);
        $this->deleteJson(route('api.layers.fields.destroy', [$layer, $field]))
            ->assertStatus(422);
        $this->putJson(route('api.layers.attributes.update', [$layer, 1]), ['column' => 'name', 'value' => 'z'])
            ->assertStatus(422);

        $this->assertFalse(Schema::hasColumn($layer->table_name, 'added_col'));
        $this->assertNull($field->fresh()->alias);
    }

    public function test_fields_and_attributes_pages_hide_editing_for_sql_view_layers(): void
    {
        $layer = $this->sqlViewLayer();
        $this->actingAs($this->editor);

        $this->get('/layers/'.$layer->id.'/fields')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Layers/Fields')->where('readOnly', true));
        $this->get('/layers/'.$layer->id.'/attributes')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Layers/Attributes/Index')->where('readOnly', true)->where('canEdit', false));
    }

    public function test_builder_derives_read_only_from_layer_metadata_not_saved_map(): void
    {
        $view = $this->sqlViewLayer();
        $plain = $this->sqlViewLayer();
        $plain->update(['metadata' => []]);
        $map = Map::query()->create([
            'organization_id' => $this->organization->id, 'user_id' => $this->editor->id, 'name' => 'M',
            // Stale saved flags: the view saved as writable, the table saved as read-only.
            'layers' => [['id' => $view->id, 'type' => 'mvt', 'read_only' => false], ['id' => $plain->id, 'type' => 'mvt', 'read_only' => true], ['id' => 'wms-x', 'type' => 'wms', 'read_only' => true]],
        ]);

        $this->actingAs($this->editor)->get('/maps/builder/'.$map->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Maps/Builder')
                ->where('initialMap.layers.0.read_only', true)
                ->where('initialMap.layers.1.read_only', false)
                ->missing('initialMap.layers.2.read_only'));
    }

    public function test_regular_layers_stay_writable(): void
    {
        $layer = $this->sqlViewLayer();
        $layer->update(['metadata' => []]);
        $id = DB::table($layer->table_name)->insertGetId(['name' => 'x']);

        $this->assertTrue(app(FeatureService::class)->delete($layer->fresh(), $id));
    }

    public function test_layer_table_exists_sees_tables_and_views(): void
    {
        Schema::create('lt_table', fn (Blueprint $t) => $t->increments('id'));
        DB::statement('CREATE VIEW lt_view AS SELECT 1 AS id');

        $this->assertTrue(LayerTable::exists('lt_table'));
        $this->assertTrue(LayerTable::exists('lt_view'));
        $this->assertFalse(LayerTable::exists('lt_missing'));
        $this->assertFalse(LayerTable::exists(null));
        $this->assertFalse(LayerTable::exists(''));
        $this->assertTrue(LayerTable::isView('lt_view'));
        $this->assertFalse(LayerTable::isView('lt_table'));
    }
}
