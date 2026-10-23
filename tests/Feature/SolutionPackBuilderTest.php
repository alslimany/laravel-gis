<?php

namespace Tests\Feature;

use App\Jobs\PublishLayerToGeoServer;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SolutionPackDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SolutionPackBuilderTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    protected function validDefinition(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'Sites',
                    'description' => 'Test sites',
                    'geometry_type' => 'Point',
                    'fields' => [
                        ['name' => 'name', 'alias' => 'Name', 'type' => 'string', 'required' => true],
                        ['name' => 'status', 'alias' => 'Status', 'type' => 'string', 'domain' => ['Open', 'Closed']],
                    ],
                ],
                [
                    'name' => 'Findings',
                    'description' => 'Findings',
                    'geometry_type' => 'None',
                    'fields' => [
                        ['name' => 'summary', 'type' => 'string'],
                    ],
                ],
            ],
            'relationships' => [
                ['name' => 'Site findings', 'parent' => 'Sites', 'child' => 'Findings', 'foreign_key' => 'site_id'],
            ],
            'features' => [],
            'maps' => [],
            'forms' => [],
            'dashboards' => [],
        ];
    }

    protected function createPack(array $definition, string $slug = 'field-kit'): SolutionPackDefinition
    {
        return SolutionPackDefinition::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'slug' => $slug,
            'name' => 'Field kit',
            'description' => 'Test pack',
            'definition' => $definition,
        ]);
    }

    public function test_index_lists_builtin_and_custom_packs(): void
    {
        $this->createPack($this->validDefinition());

        $this->actingAs($this->admin)
            ->get(route('solution-packs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SolutionPacks/Index')
                ->has('packs', 2)
                ->where('packs.0.slug', 'aec-construction')
                ->where('packs.0.custom', false)
                ->where('packs.0.id', null)
                ->where('packs.1.slug', 'field-kit')
                ->where('packs.1.custom', true)
                ->where('packs.1.installed', false)
                ->where('canManage', true));
    }

    public function test_editor_cannot_access_builder(): void
    {
        $this->actingAs($this->editor)
            ->get(route('solution-packs.index'))
            ->assertForbidden();

        $this->actingAs($this->editor)
            ->get(route('solution-packs.create'))
            ->assertForbidden();

        $pack = $this->createPack($this->validDefinition());

        $this->actingAs($this->editor)
            ->delete(route('solution-packs.destroy', $pack))
            ->assertForbidden();
    }

    public function test_admin_creates_a_pack(): void
    {
        $this->actingAs($this->admin)
            ->post(route('solution-packs.store'), [
                'name' => 'Field Kit',
                'slug' => 'field-kit',
                'description' => 'My pack',
                'definition' => $this->validDefinition(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $pack = SolutionPackDefinition::where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertSame('field-kit', $pack->slug);
        $this->assertSame('Field Kit', $pack->name);
        $this->assertSame($this->admin->id, $pack->user_id);
        $this->assertSame('Sites', $pack->definition['layers'][0]['name']);
        $this->assertCount(2, $pack->definition['layers']);
    }

    public function test_store_validates_structure(): void
    {
        $invalid = $this->validDefinition();
        $invalid['layers'][0]['geometry_type'] = 'Blob';
        $invalid['layers'][0]['fields'][0]['name'] = 'Bad Name';
        $invalid['layers'][1]['name'] = 'Sites';
        $invalid['relationships'][0]['parent'] = 'Missing';
        $invalid['relationships'][0]['foreign_key'] = 'Bad Key!';

        $this->actingAs($this->admin)
            ->from(route('solution-packs.create'))
            ->post(route('solution-packs.store'), [
                'name' => 'Broken',
                'slug' => 'broken',
                'definition' => $invalid,
            ])
            ->assertSessionHasErrors([
                'definition.layers.0.geometry_type',
                'definition.layers.0.fields.0.name',
                'definition.layers.1.name',
                'definition.relationships.0.parent',
                'definition.relationships.0.foreign_key',
            ]);

        $this->assertSame(0, SolutionPackDefinition::count());
    }

    public function test_store_rejects_reserved_and_invalid_slugs(): void
    {
        $this->actingAs($this->admin)
            ->post(route('solution-packs.store'), [
                'name' => 'Copy',
                'slug' => 'aec-construction',
                'definition' => $this->validDefinition(),
            ])
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)
            ->post(route('solution-packs.store'), [
                'name' => 'Copy',
                'slug' => 'Bad Slug!',
                'definition' => $this->validDefinition(),
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame(0, SolutionPackDefinition::count());
    }

    public function test_admin_updates_a_pack(): void
    {
        $pack = $this->createPack($this->validDefinition());
        $updated = $this->validDefinition();
        $updated['layers'][0]['description'] = 'Updated description';

        $this->actingAs($this->admin)
            ->put(route('solution-packs.update', $pack), [
                'name' => 'Field kit v2',
                'slug' => 'field-kit-v2',
                'description' => 'Updated',
                'definition' => $updated,
            ])
            ->assertRedirect(route('solution-packs.edit', $pack));

        $pack->refresh();
        $this->assertSame('field-kit-v2', $pack->slug);
        $this->assertSame('Field kit v2', $pack->name);
        $this->assertSame('Updated description', $pack->definition['layers'][0]['description']);
    }

    public function test_edit_page_renders_pack_definition(): void
    {
        $pack = $this->createPack($this->validDefinition());

        $this->actingAs($this->admin)
            ->get(route('solution-packs.edit', $pack))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SolutionPacks/Edit')
                ->where('pack.slug', 'field-kit')
                ->where('pack.definition.layers.0.name', 'Sites')
                ->has('pack.definition.relationships', 1));
    }

    public function test_packs_are_scoped_to_the_organization(): void
    {
        $pack = $this->createPack($this->validDefinition());

        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherAdmin->roles()->attach(Role::firstOrCreate(['name' => 'admin'], ['description' => 'Administrator']));

        $this->actingAs($otherAdmin)
            ->get(route('solution-packs.edit', $pack))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->put(route('solution-packs.update', $pack), [
                'name' => 'Hijacked',
                'slug' => 'field-kit',
                'definition' => $this->validDefinition(),
            ])
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->delete(route('solution-packs.destroy', $pack))
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->get(route('solution-packs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('packs', 1)
                ->where('packs.0.slug', 'aec-construction'));

        $pack->refresh();
        $this->assertSame('Field kit', $pack->name);

        $this->assertSame('field-kit', $pack->slug);
    }

    public function test_delete_removes_the_pack_but_not_installed_layers(): void
    {
        $pack = $this->createPack($this->validDefinition());

        $this->actingAs($this->admin)
            ->delete(route('solution-packs.destroy', $pack))
            ->assertRedirect(route('solution-packs.index'));

        $this->assertSame(0, SolutionPackDefinition::count());
    }

    public function test_duplicate_clones_a_builtin_pack_with_features(): void
    {
        $this->actingAs($this->admin)
            ->post(route('solution-packs.duplicate', 'aec-construction'))
            ->assertRedirect();

        $copy = SolutionPackDefinition::where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertSame('aec-construction-copy', $copy->slug);
        $this->assertSame('AEC / Construction (copy)', $copy->name);
        $this->assertCount(4, $copy->definition['layers']);
        $this->assertCount(8, $copy->definition['features']);
        $this->assertCount(1, $copy->definition['dashboards']);
        $this->assertCount(4, $copy->definition['dashboards'][0]['widgets']);
        $this->assertCount(1, $copy->definition['forms']);
        $this->assertCount(1, $copy->definition['maps']);
    }

    public function test_duplicate_clones_an_own_pack_with_a_free_slug(): void
    {
        $this->createPack($this->validDefinition(), 'field-kit');

        $this->actingAs($this->admin)
            ->post(route('solution-packs.duplicate', 'field-kit'))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('solution-packs.duplicate', 'field-kit'))
            ->assertRedirect();

        $slugs = SolutionPackDefinition::where('organization_id', $this->organization->id)
            ->pluck('slug')
            ->all();
        sort($slugs);

        $this->assertSame(['field-kit', 'field-kit-copy', 'field-kit-copy-2'], $slugs);
    }

    public function test_unknown_duplicate_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->post(route('solution-packs.duplicate', 'no-such-pack'))
            ->assertNotFound();
    }

    public function test_custom_pack_installs_and_appears_in_settings(): void
    {
        $this->createPack($this->validDefinition());

        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.install', 'field-kit'))
            ->assertRedirect(route('organization.settings'))
            ->assertSessionHas('success');

        $this->assertSame(2, Layer::where('organization_id', $this->organization->id)->count());

        $this->actingAs($this->admin)
            ->get(route('organization.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('solutionPacks', 2)
                ->where('solutionPacks.1.slug', 'field-kit')
                ->where('solutionPacks.1.custom', true)
                ->where('solutionPacks.1.installed', true)
                ->where('solutionPacks.1.unpublished', 2));
    }

    public function test_admin_publishes_pack_layers(): void
    {
        Bus::fake([PublishLayerToGeoServer::class]);

        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.install', 'aec-construction'))
            ->assertSessionHas('success');

        $this->assertSame(0, Layer::where('organization_id', $this->organization->id)->where('published', true)->count());

        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.publish', 'aec-construction'))
            ->assertRedirect(route('organization.settings'))
            ->assertSessionHas('success', 'AEC / Construction: published 4 layers to GeoServer.');

        Bus::assertDispatched(PublishLayerToGeoServer::class, 4);

        foreach (Layer::where('organization_id', $this->organization->id)->get() as $layer) {
            $this->assertTrue($layer->fresh()->isPublished());
            $this->assertSame('org_'.$this->organization->id, $layer->geoserver_workspace);
        }

        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.publish', 'aec-construction'))
            ->assertRedirect(route('organization.settings'))
            ->assertSessionHas('error', 'AEC / Construction has no unpublished layers.');
    }

    public function test_editor_cannot_publish_pack_layers(): void
    {
        $this->actingAs($this->admin)
            ->post(route('organization.solution-packs.install', 'aec-construction'))
            ->assertSessionHas('success');

        $this->actingAs($this->editor)
            ->post(route('organization.solution-packs.publish', 'aec-construction'))
            ->assertForbidden();

        $this->assertSame(0, Layer::where('organization_id', $this->organization->id)->where('published', true)->count());
    }

    public function test_command_publishes_pack_layers_with_flag(): void
    {
        Bus::fake([PublishLayerToGeoServer::class]);

        $this->artisan('solution:pack:install', [
            'slug' => 'aec-construction',
            'organization_id' => $this->organization->id,
            '--publish' => true,
        ])->assertExitCode(0);

        Bus::assertDispatched(PublishLayerToGeoServer::class, 4);
        $this->assertSame(4, Layer::where('organization_id', $this->organization->id)->where('published', true)->count());
    }

    public function test_command_installs_a_custom_pack(): void
    {
        $this->createPack($this->validDefinition());

        $this->artisan('solution:pack:install', [
            'slug' => 'field-kit',
            'organization_id' => $this->organization->id,
        ])->assertExitCode(0);

        $this->assertSame(2, Layer::where('organization_id', $this->organization->id)->count());
    }
}
