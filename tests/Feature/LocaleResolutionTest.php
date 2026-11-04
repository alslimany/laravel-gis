<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** LOC-1: the signed-in user's preferred_locale decides the language on every request. */
class LocaleResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_request_after_login_is_arabic_even_after_english_guest_choice(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        // Guest picked English on the login page; that session survives the login.
        $this->withSession(['locale' => 'en'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $response = $this->get('/dashboard');
        $response->assertOk();
        $this->assertStringContainsString('lang="ar"', (string) $response->getContent());
        $this->assertStringContainsString('dir="rtl"', (string) $response->getContent());
        $response->assertSessionHas('locale', 'ar');
    }

    public function test_stale_english_session_still_yields_arabic_for_arabic_user(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        $response = $this->actingAs($user)->withSession(['locale' => 'en'])->get('/dashboard');

        $response->assertOk()->assertHeader('Content-Language', 'ar');
        $this->assertStringContainsString('lang="ar"', (string) $response->getContent());
        $response->assertSessionHas('locale', 'ar');
    }

    public function test_inertia_shared_locale_prop_follows_user(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        $this->actingAs($user)->withSession(['locale' => 'en'])->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('locale', 'ar'));
    }

    public function test_api_honours_token_users_locale(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);
        $token = $user->createToken('loc-test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept-Language' => 'en'])
            ->getJson('/api/v1/layers')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_guest_order_is_session_then_app_locale_then_accept_language(): void
    {
        config(['app.locale' => 'ar']);

        // Fresh guest with an English browser still gets the deployment language.
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get('/login')->assertHeader('Content-Language', 'ar');

        // An explicit session choice beats APP_LOCALE.
        $this->flushSession();
        config(['app.locale' => 'ar']);
        $this->withSession(['locale' => 'en'])->withHeader('Accept-Language', 'ar')
            ->get('/login')->assertHeader('Content-Language', 'en');

        // Accept-Language only applies when APP_LOCALE is unsupported.
        $this->flushSession();
        config(['app.locale' => 'fr']);
        $this->withHeader('Accept-Language', 'ar-LY,ar;q=0.9,en;q=0.5')
            ->get('/login')->assertHeader('Content-Language', 'ar');

        $this->flushSession();
        config(['app.locale' => 'fr']); // app()->setLocale() in the previous request rewrote it
        $this->withHeader('Accept-Language', 'de-DE')
            ->get('/login')->assertHeader('Content-Language', 'en');
    }

    public function test_guest_can_switch_en_then_back_to_ar_repeatedly(): void
    {
        config(['app.locale' => 'ar']);
        $this->get('/login')->assertHeader('Content-Language', 'ar');

        foreach (['en', 'ar', 'en', 'ar'] as $next) {
            config(['app.locale' => 'ar']);
            $this->from('/login')->put(route('locale.update'), ['locale' => $next])->assertRedirect('/login');
            $this->get('/login')->assertHeader('Content-Language', $next)
                ->assertInertia(fn ($page) => $page->where('locale', $next));
        }
    }

    public function test_switching_language_updates_user_and_wins_next_request(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        $this->actingAs($user)->from('/dashboard')->put(route('locale.update'), ['locale' => 'en']);
        $this->assertSame('en', $user->refresh()->preferred_locale);

        $this->actingAs($user)->get('/dashboard')->assertHeader('Content-Language', 'en');
    }

    public function test_inertia_switch_answers_with_full_page_location_visit(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        $this->actingAs($user)->from('/dashboard')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => '1'])
            ->put(route('locale.update'), ['locale' => 'en'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/dashboard'));
        $this->assertSame('en', $user->refresh()->preferred_locale);
    }

    public function test_switch_survives_a_stale_concurrent_session_write(): void
    {
        // Race: a request that started before the switch (e.g. the COP poll) saves the old
        // session after it. The signed-in user's saved choice must still win.
        $user = User::factory()->create(['preferred_locale' => 'ar']);
        $this->actingAs($user)->from('/dashboard')->put(route('locale.update'), ['locale' => 'en']);

        $this->actingAs($user)->withSession(['locale' => 'ar'])->get('/dashboard')
            ->assertHeader('Content-Language', 'en')
            ->assertInertia(fn ($page) => $page->where('locale', 'en')->where('isRtl', false));
    }

    public function test_vary_header_includes_accept_language_and_cookie_and_keeps_existing(): void
    {
        Route::middleware('api')->get('/api/__vary_probe', fn () => response('ok')->header('Vary', 'Origin'));

        $vary = $this->get('/api/__vary_probe')->assertOk()->headers->all('vary');
        $values = array_map('trim', explode(',', implode(',', $vary)));

        $this->assertContains('Origin', $values);
        $this->assertContains('Accept-Language', $values);
        $this->assertContains('Cookie', $values);
    }
}
