<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Plugins\Concerns\UsesModulePlugin;
use Tests\Fixtures\Plugins\Hello\HelloServiceProvider;
use Tests\TestCase;

class ExtensionControllerTest extends TestCase
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

    protected function admin(): User
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        return $admin;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('extensions.index'))->assertRedirect(route('login'));
    }

    public function test_member_cannot_view_or_toggle_extensions(): void
    {
        $this->installModulePlugin();
        $member = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($member)->get(route('extensions.index'))->assertForbidden();
        $this->actingAs($member)->put(route('extensions.update', 'hello'), ['enabled' => true])->assertForbidden();

        $this->assertDatabaseMissing('organization_plugins', ['plugin_key' => 'hello']);
    }

    public function test_admin_sees_present_extensions_with_install_and_enabled_state(): void
    {
        $this->actingAs($this->admin())
            ->get(route('extensions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Extensions/Index')
                ->has('extensions', 1)
                ->where('extensions.0.key', 'hello')
                ->where('extensions.0.name', 'Hello Module')
                ->where('extensions.0.installed', false)
                ->where('extensions.0.enabled', false)
            );
    }

    public function test_admin_turns_an_installed_extension_on_for_their_organization(): void
    {
        $this->installModulePlugin();

        $this->actingAs($this->admin())
            ->put(route('extensions.update', 'hello'), ['enabled' => true])
            ->assertRedirect(route('extensions.index'))
            ->assertSessionHas('success', 'Hello Module is now on for your organization.');

        $row = OrganizationPlugin::query()->where('plugin_key', 'hello')->sole();
        $this->assertSame($this->organization->id, (int) $row->organization_id);
        $this->assertTrue($row->enabled);
        $this->assertSame(['enable:'.$this->organization->id], HelloServiceProvider::$calls);
    }

    public function test_admin_turns_an_extension_off(): void
    {
        $this->installModulePlugin();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('extensions.update', 'hello'), ['enabled' => true]);

        $this->actingAs($admin)
            ->put(route('extensions.update', 'hello'), ['enabled' => false])
            ->assertSessionHas('success', 'Hello Module is now off for your organization.');

        $this->assertFalse(OrganizationPlugin::query()->where('plugin_key', 'hello')->sole()->enabled);
    }

    public function test_extension_that_is_not_installed_cannot_be_turned_on(): void
    {
        $this->actingAs($this->admin())
            ->put(route('extensions.update', 'hello'), ['enabled' => true])
            ->assertNotFound();

        $this->assertDatabaseMissing('organization_plugins', ['plugin_key' => 'hello']);
    }

    public function test_enabled_flag_is_required(): void
    {
        $this->installModulePlugin();

        $this->actingAs($this->admin())
            ->put(route('extensions.update', 'hello'), [])
            ->assertSessionHasErrors(['enabled' => 'The enabled field is required.']);
    }
}
