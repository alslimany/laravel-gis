<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Plugins\Concerns\UsesModulePlugin;
use Tests\TestCase;

class ConsoleModulesTest extends TestCase
{
    use RefreshDatabase;
    use UsesModulePlugin;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->organization = Organization::factory()->create();
        $this->setUpModulePlugin();
    }

    protected function tearDown(): void
    {
        $this->tearDownModulePlugin();

        parent::tearDown();
    }

    protected function member(?Organization $organization = null): User
    {
        return User::factory()->create(['organization_id' => ($organization ?? $this->organization)->id]);
    }

    protected function admin(): User
    {
        $admin = $this->member();
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        return $admin;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function modules(TestResponse $response): Collection
    {
        return collect($response->viewData('page')['props']['modules']);
    }

    /**
     * @param  array<string, mixed>  $module
     * @return list<string>
     */
    protected function itemLabels(array $module): array
    {
        return collect($module['sections'])->flatMap(fn (array $section) => collect($section['items'])->pluck('label'))->all();
    }

    public function test_member_gets_core_sections_and_an_organization_module_without_admin_pages(): void
    {
        $response = $this->actingAs($this->member())->get('/dashboard');

        $modules = $this->modules($response);
        $this->assertSame(['core', 'admin'], $modules->pluck('key')->all());

        $core = $modules->firstWhere('key', 'core');
        $this->assertSame(['Data', 'Maps & apps', 'Work'], collect($core['sections'])->pluck('label')->all());
        $this->assertSame(
            ['Data imports', 'Layers', 'Catalog', 'Maps', 'Dashboards', 'Forms', 'Apps', 'Projects'],
            $this->itemLabels($core)
        );

        $organization = $modules->firstWhere('key', 'admin');
        $this->assertSame('Organization', $organization['label']);
        $this->assertSame(['Groups', 'API tokens', 'Settings'], $this->itemLabels($organization));
    }

    public function test_admin_gets_the_admin_module_with_users_extensions_and_solution_packs(): void
    {
        $response = $this->actingAs($this->admin())->get('/dashboard');

        $admin = $this->modules($response)->firstWhere('key', 'admin');
        $this->assertSame('Admin', $admin['label']);
        $this->assertSame(
            ['Users', 'Groups', 'Extensions', 'Solution packs', 'API tokens', 'Settings'],
            $this->itemLabels($admin)
        );
        $this->assertSame(route('users.index'), $admin['href']);
    }

    public function test_enabled_plugin_adds_its_module_between_core_and_admin(): void
    {
        $this->installModulePlugin();
        app(PluginManager::class)->setEnabled('hello', $this->organization, true);

        $response = $this->actingAs($this->member())->get('/dashboard');

        $modules = $this->modules($response);
        $this->assertSame(['core', 'hello', 'admin'], $modules->pluck('key')->all());

        $plugin = $modules->firstWhere('key', 'hello');
        $this->assertSame('Hello Module', $plugin['label']);
        $this->assertSame('extension', $plugin['kind']);
        $this->assertSame('orange', $plugin['accent']);
        $this->assertSame(route('hello.index'), $plugin['href']);
        // Items whose route does not exist are dropped; ungated permissions are allowed.
        $this->assertSame(['Overview', 'Settings'], $this->itemLabels($plugin));
    }

    public function test_plugin_module_is_hidden_when_disabled_or_enabled_for_another_organization(): void
    {
        $this->installModulePlugin();
        $other = Organization::factory()->create();
        app(PluginManager::class)->setEnabled('hello', $other, true);

        $this->assertSame(
            ['core', 'admin'],
            $this->modules($this->actingAs($this->member())->get('/dashboard'))->pluck('key')->all()
        );
    }

    public function test_plugin_items_behind_a_defined_gate_are_hidden_from_users_it_denies(): void
    {
        $this->installModulePlugin();
        app(PluginManager::class)->setEnabled('hello', $this->organization, true);
        Gate::define('hello.manage', fn (User $user) => $user->isAdmin());

        $memberPlugin = $this->modules($this->actingAs($this->member())->get('/dashboard'))->firstWhere('key', 'hello');
        $adminPlugin = $this->modules($this->actingAs($this->admin())->get('/dashboard'))->firstWhere('key', 'hello');

        $this->assertSame(['Overview'], $this->itemLabels($memberPlugin));
        $this->assertSame(['Overview', 'Settings'], $this->itemLabels($adminPlugin));
    }

    public function test_active_module_follows_the_current_route(): void
    {
        $this->installModulePlugin();
        app(PluginManager::class)->setEnabled('hello', $this->organization, true);
        $admin = $this->admin();

        $active = fn (string $uri) => $this->actingAs($admin)->get($uri)->viewData('page')['props']['activeModule'];

        $this->assertSame('core', $active('/dashboard'));
        $this->assertSame('core', $active(route('layers.index')));
        $this->assertSame('admin', $active(route('users.index')));
        $this->assertSame('admin', $active(route('extensions.index')));
        $this->assertSame('hello', $active(route('hello.index')));
    }

    public function test_user_without_an_organization_only_gets_an_empty_core_module(): void
    {
        $user = User::factory()->create(['organization_id' => null]);

        $modules = $this->modules($this->actingAs($user)->get('/dashboard'));

        $this->assertSame(['core'], $modules->pluck('key')->all());
        $this->assertSame([], $modules->first()['sections']);
    }
}
