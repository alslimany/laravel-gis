<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\SolutionPackDefinition;
use App\Models\User;
use App\Services\SolutionPackInstaller;
use App\SolutionPacks\AecConstructionPack;
use App\SolutionPacks\StoredPack;
use App\SolutionPacks\WorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SolutionPackWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::firstOrCreate(['name' => 'editor'], ['description' => 'Editor']);
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['description' => 'Administrator']);

        $this->organization = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->admin->roles()->attach($adminRole);
        $this->editor = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->editor->roles()->attach($editorRole);
    }

    protected function installAec(): void
    {
        app(SolutionPackInstaller::class)->install(
            new AecConstructionPack,
            $this->organization,
            $this->admin
        );
    }

    public function test_aec_pack_declares_five_workflow_steps(): void
    {
        $pack = new AecConstructionPack;
        $workflow = $pack->workflow();

        $this->assertCount(5, $workflow);
        $this->assertSame('Collect in the field', $workflow[0]['title']);
        $this->assertSame('form', $workflow[0]['kind']);
        $this->assertSame('Site inspection', $workflow[0]['target']);
        $this->assertSame('text', $workflow[1]['kind']);
        $this->assertSame('dashboard', $workflow[2]['kind']);
        $this->assertSame('map', $workflow[3]['kind']);
    }

    public function test_resolver_links_targets_after_install(): void
    {
        $this->installAec();

        $steps = app(WorkflowResolver::class)->resolve(new AecConstructionPack, $this->organization);

        $this->assertCount(5, $steps);
        $this->assertStringStartsWith('/f/', (string) $steps[0]['href']);
        $this->assertNull($steps[1]['href']);
        $this->assertMatchesRegularExpression('#^/dashboards/\d+$#', (string) $steps[2]['href']);
        $this->assertMatchesRegularExpression('#^/maps/\d+$#', (string) $steps[3]['href']);
        $this->assertTrue(collect($steps)->every(fn (array $step) => $step['description'] !== ''));
    }

    public function test_resolver_disables_missing_targets_without_failing(): void
    {
        $pack = new AecConstructionPack;

        $steps = app(WorkflowResolver::class)->resolve($pack, $this->organization);

        $this->assertCount(5, $steps);
        foreach ([0, 2, 3] as $index) {
            $this->assertNull($steps[$index]['href'], "Step {$index} should be disabled.");
        }
        $this->assertSame('text', $steps[1]['kind']);
        $this->assertNotNull($steps[1]['title']);
    }

    public function test_gallery_renders_workflow_runbook(): void
    {
        $this->installAec();

        $this->actingAs($this->editor)
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Index')
                ->has('workspaces.0.workflow', 5)
                ->where('workspaces.0.workflow.0.kind', 'form')
                ->where('workspaces.0.workflow.0.href', fn ($href) => str_starts_with((string) $href, '/f/'))
                ->where('workspaces.0.workflow.1.href', null));
    }

    public function test_pack_list_includes_workflow(): void
    {
        $this->installAec();

        $this->actingAs($this->admin)
            ->get(route('solution-packs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SolutionPacks/Index')
                ->where('packs.0.slug', 'aec-construction')
                ->has('packs.0.workflow', 5)
                ->where('packs.0.workflow.2.kind', 'dashboard'));
    }

    public function test_custom_pack_workflow_round_trips_through_builder(): void
    {
        $definition = [
            'layers' => [
                [
                    'name' => 'Sites',
                    'description' => '',
                    'geometry_type' => 'Point',
                    'fields' => [['name' => 'name', 'type' => 'string']],
                ],
            ],
            'relationships' => [],
            'features' => [],
            'maps' => [
                ['name' => 'Site map', 'basemap' => 'osm', 'layers' => ['Sites']],
            ],
            'forms' => [],
            'dashboards' => [],
            'workflow' => [
                ['title' => 'Open the map', 'description' => 'Review sites', 'kind' => 'map', 'target' => 'Site map'],
                ['title' => 'Read the note', 'description' => 'No target needed', 'kind' => 'text'],
            ],
        ];

        $response = $this->actingAs($this->admin)->post(route('solution-packs.index'), [
            'name' => 'Field kit',
            'slug' => 'field-kit',
            'description' => 'Test',
            'definition' => $definition,
        ]);

        $model = SolutionPackDefinition::where('slug', 'field-kit')->first();
        $this->assertNotNull($model);
        $this->assertSame($definition['workflow'], $model->definition['workflow']);
        $response->assertRedirect(route('solution-packs.edit', $model));

        $this->actingAs($this->admin)
            ->get(route('solution-packs.edit', $model))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SolutionPacks/Edit')
                ->where('pack.definition.workflow.0.target', 'Site map'));
    }

    public function test_workflow_target_must_exist_in_pack(): void
    {
        $this->actingAs($this->admin)
            ->post(route('solution-packs.index'), [
                'name' => 'Bad kit',
                'slug' => 'bad-kit',
                'definition' => [
                    'layers' => [
                        ['name' => 'Sites', 'geometry_type' => 'Point', 'fields' => [['name' => 'name', 'type' => 'string']]],
                    ],
                    'relationships' => [],
                    'maps' => [],
                    'forms' => [],
                    'dashboards' => [],
                    'workflow' => [
                        ['title' => 'Watch board', 'kind' => 'dashboard', 'target' => 'Missing board'],
                    ],
                ],
            ])
            ->assertSessionHasErrors('definition.workflow.0.target');
    }

    public function test_workflow_step_requires_title(): void
    {
        $this->actingAs($this->admin)
            ->post(route('solution-packs.index'), [
                'name' => 'Bad kit',
                'slug' => 'bad-kit',
                'definition' => [
                    'layers' => [
                        ['name' => 'Sites', 'geometry_type' => 'Point', 'fields' => [['name' => 'name', 'type' => 'string']]],
                    ],
                    'relationships' => [],
                    'maps' => [],
                    'forms' => [],
                    'dashboards' => [],
                    'workflow' => [
                        ['title' => '', 'kind' => 'text'],
                    ],
                ],
            ])
            ->assertSessionHasErrors('definition.workflow.0.title');
    }

    public function test_stored_pack_workflow_is_resolvable(): void
    {
        $model = SolutionPackDefinition::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'slug' => 'mini-ops',
            'name' => 'Mini ops',
            'description' => '',
            'definition' => [
                'layers' => [
                    ['name' => 'Jobs', 'geometry_type' => 'Point', 'fields' => [['name' => 'name', 'type' => 'string']]],
                ],
                'relationships' => [],
                'maps' => [],
                'forms' => [],
                'dashboards' => [],
                'workflow' => [
                    ['title' => 'Do the thing', 'kind' => 'text'],
                ],
            ],
        ]);

        $steps = app(WorkflowResolver::class)->resolve(StoredPack::fromModel($model), $this->organization);

        $this->assertCount(1, $steps);
        $this->assertSame('Do the thing', $steps[0]['title']);
        $this->assertNull($steps[0]['href']);
    }
}
