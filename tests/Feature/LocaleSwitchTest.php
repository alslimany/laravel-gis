<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_switch_locale_to_arabic(): void
    {
        $this->from('/login')
            ->put(route('locale.update'), ['locale' => 'ar'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'ar');

        $response = $this->get('/login');

        $response->assertOk();
        $this->assertStringContainsString('dir="rtl"', (string) $response->getContent());
        $this->assertStringContainsString('lang="ar"', (string) $response->getContent());
    }

    public function test_authenticated_user_persists_preferred_locale(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/dashboard')
            ->put(route('locale.update'), ['locale' => 'ar'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('locale', 'ar');

        $user->refresh();

        $this->assertSame('ar', $user->preferred_locale);
    }

    public function test_invalid_locale_returns_validation_error(): void
    {
        $this->from('/login')
            ->put(route('locale.update'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');
    }

    public function test_json_switch_returns_204_without_inertia_location(): void
    {
        $response = $this->putJson(route('locale.update'), ['locale' => 'ar'])
            ->assertNoContent();
        $this->assertNotSame(409, $response->getStatusCode());
        $this->assertFalse($response->headers->has('X-Inertia-Location'));
        $this->assertSame('ar', session('locale'));

        $this->putJson(route('locale.update'), ['locale' => 'fr'])->assertUnprocessable();
    }
}
