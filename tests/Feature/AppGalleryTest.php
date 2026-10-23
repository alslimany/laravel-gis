<?php

namespace Tests\Feature;

use App\Models\DashboardBoard;
use App\Models\Form;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\SolutionPackInstaller;
use App\SolutionPacks\AecConstructionPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::firstOrCreate(['name' => 'editor'], ['description' => 'Editor']);
        Role::firstOrCreate(['name' => 'admin'], ['description' => 'Administrator']);

        $this->organization = Organization::factory()->create();
        $this->editor = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->editor->roles()->attach($editorRole);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('apps.index'))->assertRedirect('/login');
    }

    public function test_gallery_renders_with_empty_sections(): void
    {
        $this->actingAs($this->editor)
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Index')
                ->has('workspaces', 0)
                ->has('shared', 0)
                ->has('recent', 0));
    }

    public function test_installed_pack_workspace_lists_map_form_dashboard(): void
    {
        app(SolutionPackInstaller::class)->install(
            new AecConstructionPack,
            $this->organization,
            $this->editor
        );

        $this->actingAs($this->editor)
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Index')
                ->has('workspaces', 1)
                ->where('workspaces.0.slug', 'aec-construction')
                ->has('workspaces.0.items', 3)
                ->where('workspaces.0.items.0.type', 'map')
                ->where('workspaces.0.items.1.type', 'form')
                ->where('workspaces.0.items.1.href', fn ($href) => str_starts_with((string) $href, '/f/'))
                ->where('workspaces.0.items.2.type', 'dashboard'));
    }

    public function test_uninstalled_pack_is_not_listed(): void
    {
        $this->actingAs($this->editor)
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workspaces', 0));
    }

    public function test_shared_and_recent_items_appear(): void
    {
        $map = Map::create([
            'name' => 'Shared site map',
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'layers' => [],
            'is_public' => true,
            'share_token' => 'publicmaptoken123',
        ]);
        $dashboard = DashboardBoard::create([
            'name' => 'Ops board',
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'widgets' => [],
            'is_public' => true,
            'share_token' => 'publicdashtoken123',
        ]);
        $form = Form::create([
            'name' => 'Damage report',
            'organization_id' => $this->organization->id,
            'layer_id' => null,
            'user_id' => $this->editor->id,
            'schema' => [],
            'is_public' => true,
            'share_token' => 'publicformtoken123',
        ]);

        $map->forceFill(['updated_at' => now()->subMinutes(3)])->save();
        $dashboard->forceFill(['updated_at' => now()->subMinutes(2)])->save();
        $form->forceFill(['updated_at' => now()->subMinute()])->save();

        $this->actingAs($this->editor)
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('shared', 3)
                ->where('shared.0.href', '/maps/shared/publicmaptoken123')
                ->where('shared.1.href', '/dashboards/shared/publicdashtoken123')
                ->where('shared.2.href', '/f/publicformtoken123')
                ->where('recent.0.name', 'Damage report')
                ->where('recent.1.name', 'Ops board')
                ->where('recent.2.name', 'Shared site map'));
    }

    public function test_private_items_appear_only_in_recent(): void
    {
        DashboardBoard::create([
            'name' => 'Private board',
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'widgets' => [],
            'is_public' => false,
        ]);

        $this->actingAs($this->editor)
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('shared', 0)
                ->has('recent', 1)
                ->where('recent.0.name', 'Private board'));
    }
}
