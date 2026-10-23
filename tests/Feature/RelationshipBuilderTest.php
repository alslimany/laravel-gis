<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\LayerRelationship;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RelationshipBuilderTest extends TestCase
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

    public function test_builder_page_renders_layers_with_key_stems_columns_and_relationships(): void
    {
        $parcels = $this->makeLayer('Parcels');
        $inspections = $this->makeLayer('Inspections');
        $this->makeLayer('Utilities');

        Schema::table($inspections->table_name, function (Blueprint $table) {
            $table->unsignedBigInteger('parcel_id')->nullable();
        });

        LayerRelationship::create([
            'layer_id' => $parcels->id,
            'related_layer_id' => $inspections->id,
            'name' => 'Parcel inspections',
            'foreign_key' => 'parcel_id',
        ]);

        $this->actingAs($this->editor)
            ->get(route('relationships.builder'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relationships/Builder')
                ->has('layers', 3)
                ->where('layers.0.name', 'Inspections')
                ->where('layers.0.key_stem', 'inspection')
                ->has('layers.0.columns')
                ->where('layers.1.name', 'Parcels')
                ->where('layers.1.key_stem', 'parcel')
                ->where('layers.2.name', 'Utilities')
                ->where('layers.2.key_stem', 'utility')
                ->has('relationships', 1)
                ->where('relationships.0.name', 'Parcel inspections')
                ->where('relationships.0.foreign_key', 'parcel_id')
                ->where('relationships.0.layer_id', $parcels->id)
                ->where('relationships.0.related_layer_id', $inspections->id)
                ->where('relationships.0.parent_name', 'Parcels')
                ->where('relationships.0.child_name', 'Inspections'));
    }

    public function test_builder_sees_existing_key_columns_on_a_child_table(): void
    {
        $parcels = $this->makeLayer('Parcels');
        $inspections = $this->makeLayer('Inspections');

        Schema::table($inspections->table_name, function (Blueprint $table) {
            $table->unsignedBigInteger('parcel_id')->nullable();
        });

        $this->actingAs($this->editor)
            ->get(route('relationships.builder'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relationships/Builder')
                ->where('layers.0.id', $inspections->id)
                ->where('layers.0.columns', fn ($columns) => collect($columns)->contains('parcel_id'))
                ->where('layers.1.id', $parcels->id));
    }

    public function test_builder_page_excludes_other_organizations(): void
    {
        $mine = $this->makeLayer('Parcels');

        $otherOrganization = Organization::factory()->create();
        $this->makeLayer('Foreign layer', $otherOrganization);

        $this->actingAs($this->editor)
            ->get(route('relationships.builder'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relationships/Builder')
                ->has('layers', 1)
                ->where('layers.0.id', $mine->id)
                ->where('layers.0.name', 'Parcels')
                ->has('relationships', 0));
    }

    public function test_builder_page_is_viewable_by_a_viewer(): void
    {
        $this->makeLayer('Parcels');

        $viewerRole = Role::create(['name' => 'viewer', 'description' => 'Viewer']);
        $viewer = User::factory()->create(['organization_id' => $this->organization->id]);
        $viewer->roles()->attach($viewerRole);

        $this->actingAs($viewer)
            ->get(route('relationships.builder'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relationships/Builder')
                ->has('layers', 1));
    }

    public function test_builder_page_tolerates_a_parent_deep_link(): void
    {
        $layer = $this->makeLayer('Parcels');

        $this->actingAs($this->editor)
            ->get(route('relationships.builder').'?parent='.$layer->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relationships/Builder')
                ->where('layers.0.id', $layer->id));
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
            'table_name' => 'layer_rb_'.uniqid(),
        ]);

        Schema::create($layer->table_name, function (Blueprint $table) {
            $table->increments('id');
            $table->string('label')->nullable();
        });

        return $layer;
    }
}
