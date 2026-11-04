<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureSchemaValidationTest extends TestCase
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

    public function test_api_create_rejects_schema_violations_with_422(): void
    {
        $layer = $this->makeLayerWithTable($this->schema());
        Sanctum::actingAs($this->editor, ['*']);

        $this->postJson("/api/v1/layers/{$layer->public_id}/features", [
            'attributes' => ['depth' => 'abc', 'status' => 'pending'],
        ])
            ->assertStatus(422)
            ->assertJson([
                'errors' => [
                    'name' => 'The name field is required.',
                    'depth' => 'The Depth field must be a whole number.',
                    'status' => 'The status field must be one of: open, closed.',
                ],
            ]);
    }

    public function test_api_create_coerces_valid_values(): void
    {
        $layer = $this->makeLayerWithTable($this->schema());
        Sanctum::actingAs($this->editor, ['*']);

        $this->postJson("/api/v1/layers/{$layer->public_id}/features", [
            'attributes' => [
                'name' => 'Pit A',
                'depth' => '42',
                'status' => 'open',
                'active' => 'yes',
                'surveyed_at' => '2026-09-27',
            ],
        ])->assertCreated();

        $row = DB::table($layer->table_name)->first();
        $this->assertSame('Pit A', $row->name);
        $this->assertEquals(42, $row->depth);
        $this->assertSame('open', $row->status);
        $this->assertSame('1', $row->active);
        $this->assertSame('2026-09-27', $row->surveyed_at);
    }

    public function test_session_api_feature_writes_return_422_json(): void
    {
        $layer = $this->makeLayerWithTable($this->schema());
        $featureId = DB::table($layer->table_name)->insertGetId(['name' => 'Old']);

        $this->actingAs($this->editor)
            ->postJson('/api/layers/'.$layer->id.'/features', [
                'attributes' => ['depth' => 'abc'],
                'wkt' => 'POINT(13 32)',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.depth', 'The Depth field must be a whole number.');

        $this->actingAs($this->editor)
            ->putJson('/api/layers/'.$layer->id.'/features/'.$featureId, [
                'attributes' => ['depth' => 'abc'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.depth', 'The Depth field must be a whole number.');
    }

    public function test_table_edit_validates_and_flashes_field_errors(): void
    {
        $layer = $this->makeLayerWithTable($this->schema());
        $featureId = DB::table($layer->table_name)->insertGetId(['name' => 'Old', 'depth' => '5']);

        $this->actingAs($this->editor)
            ->put('/layers/'.$layer->id.'/features/'.$featureId, [
                'attributes' => ['depth' => 'abc'],
            ])
            ->assertSessionHasErrors('depth');

        $this->assertSame('5', DB::table($layer->table_name)->where('id', $featureId)->value('depth'));
    }

    public function test_table_edit_ignores_invalid_legacy_values_outside_the_diff(): void
    {
        $layer = $this->makeLayerWithTable([
            'name' => ['type' => 'string', 'required' => true],
            'depth' => ['type' => 'integer', 'required' => true],
        ]);
        $featureId = DB::table($layer->table_name)->insertGetId([
            'name' => 'Old',
            'depth' => 'garbage',
        ]);

        $this->actingAs($this->editor)
            ->put('/layers/'.$layer->id.'/features/'.$featureId, [
                'attributes' => ['name' => 'Renamed'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $row = DB::table($layer->table_name)->where('id', $featureId)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('garbage', $row->depth);
    }

    public function test_attribute_table_cell_edit_validates_and_writes(): void
    {
        $layer = $this->makeLayerWithTable($this->schema());
        $featureId = DB::table($layer->table_name)->insertGetId(['name' => 'Old', 'status' => 'open']);

        $this->actingAs($this->editor)
            ->put('/api/layers/'.$layer->id.'/attributes/'.$featureId, [
                'column' => 'status',
                'value' => 'pending',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.status', 'The status field must be one of: open, closed.');

        $this->actingAs($this->editor)
            ->put('/api/layers/'.$layer->id.'/attributes/'.$featureId, [
                'column' => 'status',
                'value' => 'closed',
            ])
            ->assertJson(['success' => true]);

        $this->assertSame('closed', DB::table($layer->table_name)->where('id', $featureId)->value('status'));
    }

    public function test_v1_update_rejects_bad_values_and_keeps_legacy_rows_editable(): void
    {
        $layer = $this->makeLayerWithTable($this->schema());
        $featureId = DB::table($layer->table_name)->insertGetId([
            'name' => 'Old',
            'depth' => 'garbage',
        ]);
        Sanctum::actingAs($this->editor, ['*']);

        $this->putJson("/api/v1/layers/{$layer->public_id}/features/{$featureId}", [
            'attributes' => ['depth' => 'abc'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.depth', 'The Depth field must be a whole number.');

        $this->putJson("/api/v1/layers/{$layer->public_id}/features/{$featureId}", [
            'attributes' => ['name' => 'Renamed'],
        ])->assertOk();

        $row = DB::table($layer->table_name)->where('id', $featureId)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('garbage', $row->depth);
    }

    public function test_form_submission_surfaces_layer_domain_errors(): void
    {
        $layer = $this->makeLayerWithTable([
            'status' => ['type' => 'string', 'required' => true, 'domain' => ['open', 'closed']],
        ]);
        $form = $this->storeForm([
            'name' => 'Status form',
            'layer_id' => $layer->id,
            'schema' => [
                ['name' => 'status', 'label' => 'Status', 'type' => 'text', 'required' => true],
            ],
        ]);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'pending'],
        ])->assertSessionHasErrors('attributes.status');

        $this->assertDatabaseCount('form_submissions', 0);
        $this->assertDatabaseCount($layer->table_name, 0);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'closed'],
        ])->assertRedirect();

        $this->assertSame('closed', DB::table($layer->table_name)->value('status'));
    }

    public function test_form_created_layer_uses_typed_columns_and_float_fields(): void
    {
        $form = $this->storeForm([
            'name' => 'Typed inventory',
            'create_layer' => true,
            'collect_geometry' => false,
            'schema' => [
                ['name' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true],
                ['name' => 'qty', 'label' => 'Qty', 'type' => 'number'],
                ['name' => 'active', 'label' => 'Active', 'type' => 'checkbox'],
                ['name' => 'due', 'label' => 'Due', 'type' => 'date'],
            ],
        ]);

        $layer = Layer::findOrFail($form->layer_id);
        $columns = collect(Schema::getColumns($layer->table_name))->keyBy('name');
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->assertSame('DOUBLE PRECISION', strtoupper($columns['qty']['type']));
            $this->assertSame('BOOLEAN', strtoupper($columns['active']['type']));
            $this->assertSame('DATE', strtoupper($columns['due']['type']));
        } else {
            $this->assertSame('NUMERIC', strtoupper($columns['qty']['type']));
            $this->assertSame('BOOLEAN', strtoupper($columns['active']['type']));
            $this->assertSame('TEXT', strtoupper($columns['due']['type']));
        }

        $this->assertSame('float', $layer->fields()->where('name', 'qty')->value('type'));
        $this->assertSame('boolean', $layer->fields()->where('name', 'active')->value('type'));
        $this->assertSame('date', $layer->fields()->where('name', 'due')->value('type'));
        $this->assertSame('string', $layer->fields()->where('name', 'site')->value('type'));

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => [
                'site' => 'Warehouse',
                'qty' => '42.5',
                'active' => '1',
                'due' => '2026-09-27',
            ],
        ])->assertRedirect();

        $row = DB::table($layer->table_name)->first();
        $this->assertSame('Warehouse', $row->site);
        $this->assertEquals(42.5, $row->qty);
        $this->assertEquals('1', $row->active);
        $this->assertSame('2026-09-27', $row->due);
    }

    public function test_legacy_number_field_type_is_accepted_by_the_field_api(): void
    {
        $layer = $this->makeLayerWithTable(['depth' => ['type' => 'number']]);
        $field = $layer->fields()->firstOrFail();

        $this->actingAs($this->editor)
            ->put("/api/layers/{$layer->id}/fields/{$field->id}", [
                'type' => 'number',
                'alias' => 'Depth',
            ])
            ->assertOk()
            ->assertJsonPath('field.type', 'number');

        Sanctum::actingAs($this->editor, ['*']);
        $this->postJson("/api/v1/layers/{$layer->public_id}/features", [
            'attributes' => ['depth' => '7'],
        ])->assertCreated();

        $this->assertEquals(7, DB::table($layer->table_name)->value('depth'));
    }

    public function test_calculated_fields_run_after_required_validation(): void
    {
        $layer = $this->makeLayerWithTable([
            'depth' => ['type' => 'integer', 'required' => true],
            'total' => ['type' => 'float', 'calculated' => 'depth * 2'],
        ]);
        Sanctum::actingAs($this->editor, ['*']);

        $this->postJson("/api/v1/layers/{$layer->public_id}/features", [
            'attributes' => [],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.depth', 'The depth field is required.');

        $this->postJson("/api/v1/layers/{$layer->public_id}/features", [
            'attributes' => ['depth' => '5'],
        ])->assertCreated();

        $this->assertEquals(10, DB::table($layer->table_name)->value('total'));
    }

    /**
     * @return array<string, array{type: string, required?: bool, alias?: string, domain?: array<int, string>, calculated?: string}>
     */
    protected function schema(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true],
            'depth' => ['type' => 'integer', 'alias' => 'Depth'],
            'status' => ['type' => 'string', 'domain' => ['open', 'closed']],
            'active' => ['type' => 'boolean'],
            'surveyed_at' => ['type' => 'date'],
        ];
    }

    /**
     * @param  array<string, array{type: string, required?: bool, alias?: string, domain?: array<int, string>, calculated?: string}>  $fields
     */
    protected function makeLayerWithTable(array $fields): Layer
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'project_id' => null,
            'geometry_type' => null,
            'table_name' => 'layer_validation_'.uniqid(),
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
                'alias' => $definition['alias'] ?? null,
                'type' => $definition['type'],
                'domain_values' => $definition['domain'] ?? null,
                'required' => $definition['required'] ?? false,
                'calculated_expression' => $definition['calculated'] ?? null,
                'sort_order' => $order++,
            ]);
        }

        return $layer;
    }

    protected function storeForm(array $overrides = []): Form
    {
        $payload = array_merge([
            'name' => 'Site survey',
            'description' => 'Field notes',
            'is_public' => true,
            'schema' => [
                ['name' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true],
            ],
        ], $overrides);

        $this->actingAs($this->editor)
            ->post(route('forms.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Form::query()->where('name', $payload['name'])->latest('id')->firstOrFail();
    }
}
