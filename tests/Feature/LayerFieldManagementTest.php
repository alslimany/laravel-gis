<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\LayerField;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LayerFieldManagementTest extends TestCase
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

    public function test_fields_page_renders_with_layer_fields(): void
    {
        $layer = $this->makeLayerWithTable([
            'status' => ['type' => 'string', 'domain' => ['open', 'closed']],
        ]);

        $this->actingAs($this->editor)
            ->get(route('layers.fields', $layer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Layers/Fields')
                ->where('layer.id', $layer->id)
                ->has('fields', 1)
                ->where('fields.0.name', 'status')
                ->where('fields.0.domain_values.0', 'open'));
    }

    public function test_fields_page_is_forbidden_for_another_organization(): void
    {
        $layer = $this->makeLayerWithTable(['status' => ['type' => 'string']]);
        $otherOrg = Organization::factory()->create();
        $other = User::factory()->create(['organization_id' => $otherOrg->id]);
        $other->roles()->attach(Role::where('name', 'editor')->first());

        $this->actingAs($other)
            ->get(route('layers.fields', $layer))
            ->assertForbidden();
    }

    public function test_editor_can_create_a_field_and_the_column_is_added(): void
    {
        $layer = $this->makeLayerWithTable(['name' => ['type' => 'string']]);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', [
                'name' => 'area',
                'alias' => 'Area',
                'type' => 'float',
                'required' => true,
                'sort_order' => 7,
            ])
            ->assertStatus(201)
            ->assertJsonPath('field.alias', 'Area');

        $this->assertTrue(Schema::hasColumn($layer->table_name, 'area'));

        $field = LayerField::where('layer_id', $layer->id)->where('name', 'area')->firstOrFail();
        $this->assertSame('float', $field->type);
        $this->assertTrue($field->required);
        $this->assertSame(7, $field->sort_order);

        $columnType = collect(Schema::getColumns($layer->table_name))->firstWhere('name', 'area')['type'];
        $expected = DB::connection()->getDriverName() === 'pgsql' ? 'DOUBLE PRECISION' : 'NUMERIC';
        $this->assertSame($expected, strtoupper($columnType));
    }

    public function test_field_name_must_be_a_safe_unique_identifier(): void
    {
        $layer = $this->makeLayerWithTable(['name' => ['type' => 'string']]);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', ['name' => 'my field', 'type' => 'string'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', ['name' => 'name', 'type' => 'string'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', ['name' => 'id', 'type' => 'string'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_calculated_expression_must_reference_known_fields(): void
    {
        $layer = $this->makeLayerWithTable([
            'depth' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ]);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', [
                'name' => 'total',
                'type' => 'float',
                'calculated_expression' => 'depht * 2',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calculated_expression']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', [
                'name' => 'total',
                'type' => 'float',
                'calculated_expression' => 'total * 2',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calculated_expression']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/fields', [
                'name' => 'total',
                'type' => 'float',
                'calculated_expression' => 'depth * 2',
            ])
            ->assertStatus(201)
            ->assertJsonPath('field.calculated_expression', 'depth * 2');

        $field = LayerField::where('layer_id', $layer->id)->where('name', 'total')->firstOrFail();

        $this->actingAs($this->editor)
            ->putJson("/api/layers/{$layer->id}/fields/{$field->id}", [
                'calculated_expression' => 'total * 3',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calculated_expression']);

        $this->actingAs($this->editor)
            ->putJson("/api/layers/{$layer->id}/fields/{$field->id}", [
                'calculated_expression' => null,
            ])
            ->assertOk();

        $this->assertNull($field->fresh()->calculated_expression);
    }

    public function test_domain_values_are_exposed_on_the_attribute_table_page(): void
    {
        $layer = $this->makeLayerWithTable([
            'status' => ['type' => 'string', 'domain' => ['open', 'closed']],
            'note' => ['type' => 'string'],
        ]);
        DB::table($layer->table_name)->insert(['status' => 'open']);

        $this->actingAs($this->editor)
            ->get(route('layers.attributes', $layer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Layers/Attributes/Index')
                ->where('columnDomains.status', ['open', 'closed'])
                ->where('columnDomains.note', null));
    }

    public function test_field_update_changes_alias_and_clears_domain(): void
    {
        $layer = $this->makeLayerWithTable([
            'status' => ['type' => 'string', 'domain' => ['open', 'closed']],
        ]);
        $field = $layer->fields()->firstOrFail();

        $this->actingAs($this->editor)
            ->putJson("/api/layers/{$layer->id}/fields/{$field->id}", [
                'alias' => 'State',
                'domain_values' => null,
            ])
            ->assertOk()
            ->assertJsonPath('field.alias', 'State');

        $field = $field->fresh();
        $this->assertSame('State', $field->alias);
        $this->assertNull($field->domain_values);
    }

    public function test_deleting_a_field_keeps_the_column(): void
    {
        $layer = $this->makeLayerWithTable(['status' => ['type' => 'string']]);
        $field = $layer->fields()->firstOrFail();

        $this->actingAs($this->editor)
            ->deleteJson("/api/layers/{$layer->id}/fields/{$field->id}")
            ->assertOk();

        $this->assertDatabaseMissing('layer_fields', ['id' => $field->id]);
        $this->assertTrue(Schema::hasColumn($layer->table_name, 'status'));
    }

    /**
     * @param  array<string, array{type: string, required?: bool, domain?: array<int, string>}>  $fields
     */
    protected function makeLayerWithTable(array $fields): Layer
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'project_id' => null,
            'geometry_type' => null,
            'table_name' => 'layer_fields_'.uniqid(),
        ]);

        Schema::create($layer->table_name, function (Blueprint $table) use ($fields) {
            $table->increments('id');
            foreach (array_keys($fields) as $name) {
                $table->string($name)->nullable();
            }
        });

        $order = 0;
        foreach ($fields as $name => $definition) {
            LayerField::create([
                'layer_id' => $layer->id,
                'name' => $name,
                'type' => $definition['type'],
                'domain_values' => $definition['domain'] ?? null,
                'required' => $definition['required'] ?? false,
                'sort_order' => $order++,
            ]);
        }

        return $layer;
    }
}
