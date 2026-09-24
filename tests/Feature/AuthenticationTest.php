<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'closed@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
        $this->assertDatabaseMissing('users', [
            'email' => 'closed@example.com',
        ]);
    }

    public function test_user_can_register_when_open(): void
    {
        config(['app.allow_registration' => true]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/home');
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_guest_login_uses_console_name_and_hides_register(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Lumina GIS', false);
        $response->assertDontSee('Laravel', false);
        $response->assertDontSee('/register', false);
        $response->assertDontSee('>Log in<', false);
    }

    public function test_guest_login_keeps_the_product_name_when_one_organization_exists(): void
    {
        Organization::factory()->create(['name' => 'Atlas Survey']);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Lumina GIS', false);
        $response->assertDontSee('Atlas Survey', false);
        $response->assertDontSee('Laravel', false);
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_user_with_role_can_be_identified(): void
    {
        Role::create(['name' => 'admin', 'description' => 'Administrator']);

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin')->first());

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->isAdmin());
    }

    public function test_user_can_have_multiple_roles(): void
    {
        Role::create(['name' => 'admin', 'description' => 'Administrator']);
        Role::create(['name' => 'editor', 'description' => 'Editor']);

        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('name', ['admin', 'editor'])->get());

        $this->assertTrue($user->hasAnyRole(['admin', 'editor']));
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('editor'));
    }
}
