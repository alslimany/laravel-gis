<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\LayerRelationship;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RelationshipManagementTest extends TestCase
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
        $this->editor = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->editor->roles()->attach($editorRole);
    }

    public function test_relationships_page_renders_layer_and_its_relationships(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');

        LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);

        $this->actingAs($this->editor)
            ->get(route('layers.relationships', $parent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Layers/Relationships')
                ->where('layer.id', $parent->id)
                ->has('relationships', 1)
                ->where('relationships.0.foreign_key', 'building_id')
                ->where('relationships.0.related_layer.name', 'inspections'));
    }

    public function test_relationships_page_is_forbidden_for_another_organization(): void
    {
        $parent = $this->makeLayer('buildings');
        $otherOrg = Organization::factory()->create();
        $other = User::factory()->create(['organization_id' => $otherOrg->id]);
        $other->roles()->attach(Role::where('name', 'editor')->first());

        $this->actingAs($other)
            ->get(route('layers.relationships', $parent))
            ->assertForbidden();
    }

    public function test_editor_can_create_a_relationship_and_the_key_column_is_added(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$parent->id.'/relationships', [
                'name' => 'Inspections',
                'related_layer_id' => $child->id,
                'foreign_key' => 'building_id',
            ])
            ->assertStatus(201)
            ->assertJsonPath('relationship.foreign_key', 'building_id')
            ->assertJsonPath('relationship.related_layer.id', $child->id);

        $this->assertTrue(Schema::hasColumn($child->table_name, 'building_id'));

        $columnType = collect(Schema::getColumns($child->table_name))->firstWhere('name', 'building_id')['type'];
        $expected = DB::connection()->getDriverName() === 'pgsql' ? 'BIGINT' : 'INTEGER';
        $this->assertSame($expected, strtoupper($columnType));

        $this->assertDatabaseHas('layer_relationships', [
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'foreign_key' => 'building_id',
        ]);
    }

    public function test_relationship_creation_rejects_self_other_organization_and_bad_key_names(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');
        $otherOrg = Organization::factory()->create();
        $foreign = $this->makeLayer('foreign_layer', $otherOrg);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$parent->id.'/relationships', [
                'name' => 'Self',
                'related_layer_id' => $parent->id,
                'foreign_key' => 'parent_id',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['related_layer_id']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$parent->id.'/relationships', [
                'name' => 'Foreign',
                'related_layer_id' => $foreign->id,
                'foreign_key' => 'parent_id',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['related_layer_id']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$parent->id.'/relationships', [
                'name' => 'Bad key',
                'related_layer_id' => $child->id,
                'foreign_key' => 'bad key',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['foreign_key']);

        $this->assertDatabaseCount('layer_relationships', 0);
    }

    public function test_relationships_index_returns_related_layers_as_json(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');
        $relationship = LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);

        $this->actingAs($this->editor)
            ->getJson('/api/layers/'.$parent->id.'/relationships')
            ->assertOk()
            ->assertJsonCount(1, 'relationships')
            ->assertJsonPath('relationships.0.id', $relationship->id)
            ->assertJsonPath('relationships.0.related_layer.name', 'inspections');
    }

    public function test_features_endpoint_returns_children_matching_the_parent_id(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');
        LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);
        Schema::table($child->table_name, function (Blueprint $table) {
            $table->integer('building_id')->nullable();
        });
        DB::table($child->table_name)->insert([
            ['building_id' => 42, 'label' => 'Match'],
            ['building_id' => 7, 'label' => 'Other'],
        ]);
        $this->actingAs($this->editor)
            ->getJson('/api/layers/'.$parent->id.'/relationships/1/features?parent_id=42')
            ->assertOk()
            ->assertJsonPath('features.0.label', 'Match')
            ->assertJsonCount(1, 'features');

        $this->actingAs($this->editor)
            ->getJson('/api/layers/'.$parent->id.'/relationships/1/features?parent_id=999')
            ->assertOk()
            ->assertJsonCount(0, 'features');
    }

    public function test_features_endpoint_requires_a_parent_id(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');
        $relationship = LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);

        $this->actingAs($this->editor)
            ->getJson('/api/layers/'.$parent->id.'/relationships/'.$relationship->id.'/features')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_relationship_endpoints_are_forbidden_for_another_organization(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');
        $relationship = LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);
        $otherOrg = Organization::factory()->create();
        $other = User::factory()->create(['organization_id' => $otherOrg->id]);
        $other->roles()->attach(Role::where('name', 'editor')->first());

        $this->actingAs($other)
            ->getJson('/api/layers/'.$parent->id.'/relationships')
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson('/api/layers/'.$parent->id.'/relationships', [
                'name' => 'Stolen',
                'related_layer_id' => $child->id,
                'foreign_key' => 'building_id',
            ])
            ->assertForbidden();

        $this->actingAs($other)
            ->getJson('/api/layers/'.$parent->id.'/relationships/'.$relationship->id.'/features?parent_id=1')
            ->assertForbidden();
    }

    public function test_destroy_requires_the_relationship_to_belong_to_the_layer(): void
    {
        $parent = $this->makeLayer('buildings');
        $otherParent = $this->makeLayer('roads');
        $child = $this->makeLayer('inspections');
        $relationship = LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);

        $this->actingAs($this->editor)
            ->deleteJson('/api/layers/'.$otherParent->id.'/relationships/'.$relationship->id)
            ->assertNotFound();

        $this->actingAs($this->editor)
            ->deleteJson('/api/layers/'.$parent->id.'/relationships/'.$relationship->id)
            ->assertOk();

        $this->assertDatabaseMissing('layer_relationships', ['id' => $relationship->id]);
    }

    public function test_deleting_a_layer_cascades_its_relationships(): void
    {
        $parent = $this->makeLayer('buildings');
        $child = $this->makeLayer('inspections');
        LayerRelationship::create([
            'layer_id' => $parent->id,
            'related_layer_id' => $child->id,
            'name' => 'Inspections',
            'foreign_key' => 'building_id',
        ]);

        $parent->delete();

        $this->assertDatabaseCount('layer_relationships', 0);
    }

    protected function makeLayer(string $name, ?Organization $organization = null): Layer
    {
        $organization ??= $this->organization;

        $layer = Layer::factory()->create([
            'user_id' => $this->editor->id,
            'organization_id' => $organization->id,
            'project_id' => null,
            'geometry_type' => null,
            'name' => $name,
            'table_name' => 'layer_rel_'.uniqid(),
        ]);

        Schema::create($layer->table_name, function (Blueprint $table) {
            $table->increments('id');
            $table->string('label')->nullable();
        });

        return $layer;
    }
}
