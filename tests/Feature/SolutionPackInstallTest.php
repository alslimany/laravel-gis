<?php

namespace Tests\Feature;

use App\Models\DashboardBoard;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\LayerRelationship;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\SolutionPackInstaller;
use App\SolutionPacks\AecConstructionPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SolutionPackInstallTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $adminRole = Role::create(['name' => 'admin', 'description' => 'Administrator']);

        $this->organization = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->admin->roles()->attach($adminRole);
        $this->editor = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->editor->roles()->attach($editorRole);
    }

    protected function install(?Organization $organization = null, ?User $owner = null): array
    {
        return app(SolutionPackInstaller::class)->install(
            new AecConstructionPack,
            $organization ?? $this->organization,
            $owner ?? $this->admin
        );
    }

    protected function packLayer(string $name, ?Organization $organization = null): Layer
    {
        return Layer::where('organization_id', ($organization ?? $this->organization)->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    public function test_installer_creates_layers_fields_and_domains(): void
    {
        $summary = $this->install();

        $this->assertTrue($summary['installed']);
        $this->assertCount(4, $summary['layers']);
        $this->assertSame(
            ['Parcels', 'Buildings', 'Utilities', 'Inspections'],
            array_column($summary['layers'], 'name')
        );

        $parcels = $this->packLayer('Parcels');
        $this->assertSame('Polygon', $parcels->geometry_type);
        $this->assertSame('aec-construction', $parcels->metadata['solution_pack']);
        $this->assertFalse($parcels->published);
        $this->assertSame(2, $parcels->feature_count);
        $this->assertTrue(Schema::hasTable($parcels->table_name));

        $zoning = LayerField::where('layer_id', $parcels->id)->where('name', 'zoning')->firstOrFail();
        $this->assertSame(
            ['Residential', 'Commercial', 'Industrial', 'Mixed use', 'Agricultural'],
            $zoning->domain_values
        );

        $calculated = LayerField::where('layer_id', $parcels->id)->where('name', 'land_area_ha')->firstOrFail();
        $this->assertSame('land_area_m2 / 10000', $calculated->calculated_expression);

        $name = LayerField::where('layer_id', $parcels->id)->where('name', 'name')->firstOrFail();
        $this->assertTrue($name->required);

        $buildings = $this->packLayer('Buildings');
        $this->assertSame('Polygon', $buildings->geometry_type);
        $this->assertSame('LineString', $this->packLayer('Utilities')->geometry_type);
        $this->assertSame('Point', $this->packLayer('Inspections')->geometry_type);
    }

    public function test_installer_creates_relationships_and_key_columns(): void
    {
        $summary = $this->install();

        $this->assertCount(2, $summary['relationships']);
        $this->assertSame(2, LayerRelationship::count());

        $parcels = $this->packLayer('Parcels');
        $buildings = $this->packLayer('Buildings');
        $inspections = $this->packLayer('Inspections');

        $this->assertTrue(Schema::hasColumn($buildings->table_name, 'parcel_id'));
        $this->assertTrue(Schema::hasColumn($inspections->table_name, 'building_id'));

        $parcelRelationship = LayerRelationship::where('foreign_key', 'parcel_id')->firstOrFail();
        $this->assertSame($parcels->id, $parcelRelationship->layer_id);
        $this->assertSame($buildings->id, $parcelRelationship->related_layer_id);

        $inspectionRelationship = LayerRelationship::where('foreign_key', 'building_id')->firstOrFail();
        $this->assertSame($buildings->id, $inspectionRelationship->layer_id);
        $this->assertSame($inspections->id, $inspectionRelationship->related_layer_id);
    }

    public function test_installer_seeds_sample_features(): void
    {
        $summary = $this->install();
        $this->assertSame(8, $summary['features']);

        $parcels = $this->packLayer('Parcels');
        $buildings = $this->packLayer('Buildings');
        $utilities = $this->packLayer('Utilities');
        $inspections = $this->packLayer('Inspections');

        $this->assertSame(2, DB::table($parcels->table_name)->count());
        $this->assertSame(2, DB::table($buildings->table_name)->count());
        $this->assertSame(2, DB::table($utilities->table_name)->count());
        $this->assertSame(2, DB::table($inspections->table_name)->count());

        $this->assertSame(2, $parcels->fresh()->feature_count);
        $this->assertSame(2, $inspections->fresh()->feature_count);

        $lot = DB::table($parcels->table_name)->where('name', 'Riverside lot A')->firstOrFail();
        $this->assertEqualsWithDelta(1.01, (float) $lot->land_area_ha, 0.0001);
        $this->assertNotEmpty($lot->geom);

        $tower = DB::table($buildings->table_name)->where('name', 'Riverside tower')->firstOrFail();
        $this->assertEquals($lot->id, $tower->parcel_id);
        $this->assertSame('Under construction', $tower->status);

        $pass = DB::table($inspections->table_name)->where('result', 'Pass')->firstOrFail();
        $this->assertEquals($tower->id, $pass->building_id);

        $feature = app(FeatureService::class)->find($parcels, $lot->id);
        $this->assertNotNull($feature);
        $this->assertSame('Polygon', $feature['geometry']['type'] ?? null);
        $this->assertSame('Riverside lot A', $feature['properties']['name']);
    }

    public function test_install_is_idempotent(): void
    {
        $first = $this->install();
        $second = $this->install();

        $this->assertTrue($first['installed']);
        $this->assertFalse($second['installed']);
        $this->assertSame(0, collect($second['layers'])->where('created', true)->count());
        $this->assertSame([], $second['relationships']);
        $this->assertSame(0, $second['features']);
        $this->assertSame(0, $second['maps']);
        $this->assertSame(0, $second['forms']);
        $this->assertSame(0, $second['dashboards']);

        $this->assertSame(4, Layer::where('organization_id', $this->organization->id)->count());
        $this->assertSame(2, LayerRelationship::count());
        $this->assertSame(1, Map::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Form::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, DashboardBoard::where('organization_id', $this->organization->id)->count());
        $this->assertSame(8, DB::table($this->packLayer('Parcels')->table_name)->count()
            + DB::table($this->packLayer('Buildings')->table_name)->count()
            + DB::table($this->packLayer('Utilities')->table_name)->count()
            + DB::table($this->packLayer('Inspections')->table_name)->count());
    }

    public function test_install_is_isolated_between_organizations(): void
    {
        $this->install();

        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $summary = $this->install($otherOrganization, $otherAdmin);

        $this->assertTrue($summary['installed']);
        $this->assertSame(4, Layer::where('organization_id', $this->organization->id)->count());
        $this->assertSame(4, Layer::where('organization_id', $otherOrganization->id)->count());
        $this->assertNotSame(
            $this->packLayer('Parcels')->table_name,
            $this->packLayer('Parcels', $otherOrganization)->table_name
        );

        $this->assertSame(1, Map::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Map::where('organization_id', $otherOrganization->id)->count());
        $this->assertSame(1, Form::where('organization_id', $otherOrganization->id)->count());
        $this->assertSame(1, DashboardBoard::where('organization_id', $otherOrganization->id)->count());

        $otherMap = Map::where('organization_id', $otherOrganization->id)->firstOrFail();
        $this->assertSame($this->packLayer('Parcels', $otherOrganization)->id, $otherMap->layers[0]['id']);
    }

    public function test_command_installs_pack(): void
    {
        $this->artisan('solution:pack:install', [
            'slug' => 'aec-construction',
            'organization_id' => $this->organization->id,
        ])->assertExitCode(0);

        $this->assertSame(4, Layer::where('organization_id', $this->organization->id)->count());
        $this->assertTrue(app(SolutionPackInstaller::class)->isInstalled(new AecConstructionPack, $this->organization));
    }

    public function test_command_fails_for_unknown_pack(): void
    {
        $this->artisan('solution:pack:install', [
            'slug' => 'unknown-pack',
            'organization_id' => $this->organization->id,
        ])->assertExitCode(1);

        $this->assertSame(0, Layer::where('organization_id', $this->organization->id)->count());
    }

    public function test_settings_page_lists_packs(): void
    {
        $this->actingAs($this->admin)
            ->get(route('organization.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organization/Settings')
                ->has('solutionPacks', 1)
                ->where('solutionPacks.0.slug', 'aec-construction')
                ->where('solutionPacks.0.name', 'AEC / Construction')
                ->where('solutionPacks.0.contents', '4 layers · 2 relationships · 1 map · 1 form · 1 dashboard · 8 sample features')
                ->where('solutionPacks.0.installed', false)
                ->where('canInstallPacks', true));
    }

    public function test_install_requires_admin(): void
    {
        $this->actingAs($this->editor)
            ->post(route('organization.solution-packs.install', 'aec-construction'))
            ->assertForbidden();

        $this->assertSame(0, Layer::where('organization_id', $this->organization->id)->count());
    }

    public function test_admin_can_install_from_settings(): void
    {
        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.install', 'aec-construction'))
            ->assertRedirect(route('organization.settings'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 map, 1 form, 1 dashboard'));

        $this->assertSame(4, Layer::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Map::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Form::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, DashboardBoard::where('organization_id', $this->organization->id)->count());

        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.install', 'aec-construction'))
            ->assertRedirect(route('organization.settings'))
            ->assertSessionHas('success', 'AEC / Construction is already installed.');

        $this->assertSame(4, Layer::where('organization_id', $this->organization->id)->count());
    }

    public function test_unknown_pack_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.install', 'unknown-pack'))
            ->assertNotFound();
    }

    public function test_installer_creates_workspace_map_form_and_dashboard(): void
    {
        $summary = $this->install();

        $this->assertSame(1, $summary['maps']);
        $this->assertSame(1, $summary['forms']);
        $this->assertSame(1, $summary['dashboards']);
        $this->assertTrue(app(SolutionPackInstaller::class)->isInstalled(new AecConstructionPack, $this->organization));

        $buildings = $this->packLayer('Buildings');
        $inspections = $this->packLayer('Inspections');
        $parcels = $this->packLayer('Parcels');

        $this->assertSame('simple', $parcels->style_config['renderer']);
        $this->assertSame('#f59e0b', $parcels->style_config['symbol']['fillColor']);
        $this->assertSame('#3b82f6', $buildings->style_config['symbol']['fillColor']);
        $this->assertSame('#0e7490', $this->packLayer('Utilities')->style_config['symbol']['strokeColor']);
        $this->assertSame(7, $inspections->style_config['symbol']['pointRadius']);

        $map = Map::where('organization_id', $this->organization->id)->where('name', 'AEC Site Overview')->firstOrFail();
        $this->assertFalse($map->is_public);
        $this->assertSame('osm', $map->basemap);
        $this->assertSame([-122.4195, 37.7745], $map->viewport['center']);
        $this->assertSame(16, $map->viewport['zoom']);
        $this->assertCount(4, $map->layers);
        $this->assertSame($parcels->id, $map->layers[0]['id']);
        $this->assertSame('mvt', $map->layers[0]['type']);
        $this->assertSame('/api/layers/'.$parcels->id.'/tiles/{z}/{x}/{y}.mvt', $map->layers[0]['mvtUrl']);
        $this->assertSame('Polygon', $map->layers[0]['geometry_type']);
        $this->assertSame($inspections->id, $map->layers[3]['id']);

        $form = Form::where('organization_id', $this->organization->id)->where('name', 'Site inspection')->firstOrFail();
        $this->assertSame($inspections->id, $form->layer_id);
        $this->assertSame($this->admin->id, $form->user_id);
        $this->assertTrue($form->is_public);
        $this->assertNotEmpty($form->share_token);
        $this->assertCount(5, $form->schema);
        $this->assertSame('select', $form->schema[0]['type']);
        $this->assertTrue($form->schema[0]['required']);
        $this->assertSame(['Safety', 'Quality', 'Progress', 'Environmental', 'Fire'], $form->schema[0]['options']);
        $this->assertSame('date', $form->schema[3]['type']);
        $this->assertFalse($form->schema[4]['required']);

        $dashboard = DashboardBoard::where('organization_id', $this->organization->id)->where('name', 'AEC progress')->firstOrFail();
        $this->assertSame($this->admin->id, $dashboard->user_id);
        $this->assertFalse($dashboard->is_public);
        $this->assertCount(4, $dashboard->widgets);
        $this->assertSame('indicator', $dashboard->widgets[0]['type']);
        $this->assertSame($buildings->id, $dashboard->widgets[0]['layer_id']);
        $this->assertSame('count', $dashboard->widgets[0]['aggregation']);
        $this->assertSame('indicator', $dashboard->widgets[1]['type']);
        $this->assertSame($inspections->id, $dashboard->widgets[1]['layer_id']);
        $this->assertSame('serial', $dashboard->widgets[2]['type']);
        $this->assertSame('status', $dashboard->widgets[2]['group_by']);
        $this->assertSame('bar', $dashboard->widgets[2]['chart_style']);
        $this->assertSame('pie', $dashboard->widgets[3]['type']);
        $this->assertSame('result', $dashboard->widgets[3]['group_by']);
        $this->assertArrayHasKey('layout', $dashboard->widgets[0]);
    }

    public function test_public_pack_form_submits_an_inspection_feature(): void
    {
        Http::fake();
        $this->install();

        $form = Form::where('organization_id', $this->organization->id)->where('name', 'Site inspection')->firstOrFail();
        $inspections = $this->packLayer('Inspections');

        $this->get(route('forms.public.show', $form->share_token))->assertOk();

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['inspection_type' => 'Safety', 'result' => 'Pass'],
            'latitude' => 37.7747,
            'longitude' => -122.4194,
        ])->assertSessionHasErrors('attributes.inspector');
        $this->assertSame(2, DB::table($inspections->table_name)->count());

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => [
                'inspection_type' => 'Safety',
                'result' => 'Pass',
                'inspector' => 'A. Novak',
                'inspection_date' => '2026-10-01',
                'notes' => 'Gate lock missing.',
            ],
            'latitude' => 37.7747,
            'longitude' => -122.4194,
        ])->assertRedirect(route('forms.public.show', $form->share_token));

        $feature = DB::table($inspections->table_name)->where('inspector', 'A. Novak')->firstOrFail();
        $this->assertSame('Pass', $feature->result);
        $this->assertSame('2026-10-01', $feature->inspection_date);
        $this->assertNotEmpty($feature->geom);
        $this->assertSame(3, DB::table($inspections->table_name)->count());
        $this->assertSame(3, $inspections->fresh()->feature_count);

        $submission = FormSubmission::where('form_id', $form->id)->firstOrFail();
        $this->assertEquals($feature->id, $submission->feature_id);
        $this->assertSame($inspections->id, $submission->layer_id);
        $this->assertNotEmpty($submission->geometry_wkt);
    }

    public function test_dashboard_page_renders_widget_data(): void
    {
        $this->install();

        $dashboard = DashboardBoard::where('organization_id', $this->organization->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('dashboards.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboards/Show')
                ->has('widgetData', 4)
                ->where('widgetData.0.status', 'ok')
                ->where('widgetData.0.value', 2)
                ->where('widgetData.1.value', 2)
                ->where('widgetData.2.status', 'ok')
                ->where('widgetData.3.status', 'ok'));
    }
}
