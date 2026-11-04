<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Lumina\EarlyWarning\Support\AdvisoryCopy;
use Tests\TestCase;

/** UI-1: /x/early-warning/warnings is a real page for browsers and JSON only for API callers. */
class WarningsPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'early-warning', 'version' => '0.4.0', 'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(), 'meta' => [],
        ])->save();
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'preferred_locale' => 'ar']);
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $org->id, 'plugin_key' => 'early-warning', 'enabled' => true, 'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($org->id);
        app(PluginManager::class)->forgetInstalled();

        return $user;
    }

    public function test_browser_gets_inertia_page_with_filters(): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->get('/x/early-warning/warnings?hours=12&level=orange&q=wadi');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('EarlyWarning/Warnings')
            ->where('filters.hours', 12)
            ->where('filters.level', 'orange')
            ->where('filters.q', 'wadi')
            ->has('basins')
            ->where('hour_options', [6, 12, 24, 48, 72])
            ->where('advisory', __('ew.advisory.message', [], 'ar')));
    }

    public function test_json_callers_still_get_the_feed(): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->getJson('/x/early-warning/warnings?hours=3');
        $response->assertOk()->assertJsonStructure(['plugin', 'advisory', 'generated_at', 'hours', 'basins']);
        $this->assertCount(3, $response->json('hours'));

        $this->actingAs($user)->get('/x/early-warning/warnings?format=json&hours=2')
            ->assertOk()->assertHeader('Content-Type', 'application/json');
    }

    public function test_advisory_copy_is_arabic_for_arabic_locale(): void
    {
        app()->setLocale('ar');
        $this->assertSame('دعم قرار مبني على التوقعات فقط — وليس إنذارًا رسميًا.', AdvisoryCopy::message());
        app()->setLocale('en');
        $this->assertStringContainsString('not an official warning', AdvisoryCopy::message());
    }
}
