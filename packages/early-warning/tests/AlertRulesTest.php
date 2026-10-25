<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\EarlyWarning\Alerts\AlertRuleEvaluator;
use Lumina\EarlyWarning\Alerts\DefaultAlertRules;
use Lumina\EarlyWarning\Jobs\EvaluateAlertsJob;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Phase 2: AlertRule CRUD, defaults, EvaluateAlertsJob. */
class AlertRulesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', [
            '--path' => EarlyWarningServiceProvider::migrationsPath(),
            '--realpath' => true,
        ])->assertExitCode(0);

        (new InstalledPlugin)->forceFill([
            'plugin_key' => 'early-warning',
            'version' => '0.5.0',
            'status' => InstalledPlugin::STATUS_INSTALLED,
            'installed_at' => now(),
            'meta' => [],
        ])->save();

        $this->org = Organization::factory()->create();
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['description' => 'Administrator'],
        );
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->roles()->attach($adminRole->id, ['organization_id' => $this->org->id]);
        (new OrganizationPlugin)->forceFill([
            'organization_id' => $this->org->id,
            'plugin_key' => 'early-warning',
            'enabled' => true,
            'enabled_at' => now(),
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();
        app(PluginManager::class)->registerPermissionGates();

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
        ]);
        Subscription::query()->create([
            'organization_id' => $this->org->id,
            'basin_code' => 'TRP-HIRA',
        ]);
    }

    public function test_seeds_tripoli_default_rules_idempotently(): void
    {
        $seeder = app(DefaultAlertRules::class);
        $first = $seeder->seed($this->org);
        $second = $seeder->seed($this->org);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
        $this->assertSame($first, AlertRule::query()->where('organization_id', $this->org->id)->count());
        $this->assertTrue(
            AlertRule::query()->where('organization_id', $this->org->id)->where('metric', 'precip_6h_mm')->exists(),
        );
    }

    public function test_index_lists_rules_via_inertia(): void
    {
        app(DefaultAlertRules::class)->seed($this->org);

        $this->actingAs($this->user)
            ->get('/x/early-warning/alert-rules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EarlyWarning/AlertRules')
                ->has('rules')
                ->has('calibration_note')
                ->has('recent_logs')
                ->has('channel_settings')
            );
    }

    public function test_store_and_toggle_rule(): void
    {
        $this->actingAs($this->user)
            ->post('/x/early-warning/alert-rules', [
                'name' => 'Test heavy rain',
                'metric' => 'precip_6h_mm',
                'operator' => '>=',
                'threshold' => 25,
                'risk_level_min' => 'yellow',
                'enabled' => true,
                'channels' => ['log'],
            ])
            ->assertRedirect(route('early-warning.alert-rules.index'));

        $rule = AlertRule::query()->where('name', 'Test heavy rain')->first();
        $this->assertNotNull($rule);
        $this->assertTrue($rule->enabled);

        $this->actingAs($this->user)
            ->put("/x/early-warning/alert-rules/{$rule->id}", ['enabled' => false])
            ->assertRedirect(route('early-warning.alert-rules.index'));

        $this->assertFalse($rule->fresh()->enabled);
    }

    public function test_evaluator_writes_alert_log_and_dedupes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        $rule = AlertRule::query()->create([
            'organization_id' => $this->org->id,
            'name' => 'Heavy 6h',
            'basin_code' => null,
            'metric' => 'precip_6h_mm',
            'operator' => '>=',
            'threshold' => 20,
            'risk_level_min' => null,
            'channels' => ['log'],
            'enabled' => true,
        ]);

        foreach (range(0, 5) as $h) {
            Forecast::query()->create([
                'basin_code' => 'TRP-HIRA',
                'source' => 'open-meteo',
                'fetched_at' => now(),
                'forecast_at' => now()->addHours($h),
                'precipitation_mm' => $h === 0 ? 25.0 : 0.0,
            ]);
        }

        RiskAssessment::query()->create([
            'organization_id' => $this->org->id,
            'basin_code' => 'TRP-HIRA',
            'forecast_at' => now(),
            'valid_from' => now(),
            'valid_until' => now()->addHour(),
            'risk_level' => 'orange',
            'score' => 55,
            'inputs' => [],
        ]);

        $evaluator = app(AlertRuleEvaluator::class);
        $this->assertSame(1, $evaluator->evaluateOrganization($this->org->id));
        $this->assertSame(1, AlertLog::query()->where('alert_rule_id', $rule->id)->count());

        $this->assertSame(0, $evaluator->evaluateOrganization($this->org->id));
        $this->assertSame(1, AlertLog::query()->where('alert_rule_id', $rule->id)->count());

        Carbon::setTestNow();
    }

    public function test_evaluate_alerts_job_and_artisan_sync(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        AlertRule::query()->create([
            'organization_id' => $this->org->id,
            'name' => 'Score gate',
            'metric' => 'risk_score',
            'operator' => '>=',
            'threshold' => 40,
            'risk_level_min' => null,
            'channels' => ['log'],
            'enabled' => true,
        ]);

        Forecast::query()->create([
            'basin_code' => 'TRP-HIRA',
            'source' => 'open-meteo',
            'fetched_at' => now(),
            'forecast_at' => now(),
            'precipitation_mm' => 1,
        ]);

        RiskAssessment::query()->create([
            'organization_id' => $this->org->id,
            'basin_code' => 'TRP-HIRA',
            'forecast_at' => now(),
            'valid_from' => now(),
            'valid_until' => now()->addHour(),
            'risk_level' => 'orange',
            'score' => 60,
            'inputs' => [],
        ]);

        (new EvaluateAlertsJob)->handle(
            app(AlertRuleEvaluator::class),
            app(PluginManager::class),
        );

        $this->assertGreaterThan(0, AlertLog::query()->where('organization_id', $this->org->id)->count());

        $this->artisan('early-warning:evaluate-alerts', ['--sync' => true])->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_cannot_update_other_org_rule(): void
    {
        $other = Organization::factory()->create();
        $rule = AlertRule::query()->create([
            'organization_id' => $other->id,
            'name' => 'Foreign',
            'metric' => 'precip_6h_mm',
            'operator' => '>=',
            'threshold' => 10,
            'enabled' => true,
            'channels' => ['log'],
        ]);

        $this->actingAs($this->user)
            ->put("/x/early-warning/alert-rules/{$rule->id}", ['enabled' => false])
            ->assertNotFound();
    }
}
