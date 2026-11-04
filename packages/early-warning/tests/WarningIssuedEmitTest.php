<?php

namespace Lumina\EarlyWarning\Tests;

use App\Events\WarningIssued;
use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lumina\EarlyWarning\Alerts\AlertRuleEvaluator;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Threshold evaluation emits Core WarningIssued (no Disaster types). */
class WarningIssuedEmitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

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
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->roles()->attach($adminRole->id, ['organization_id' => $this->org->id]);
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

    public function test_threshold_crossing_emits_warning_issued_with_cap_like_fields(): void
    {
        Event::fake([WarningIssued::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        AlertRule::query()->create([
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

        $logged = app(AlertRuleEvaluator::class)->evaluateOrganization($this->org->id);
        $this->assertSame(1, $logged);
        $log = AlertLog::query()->where('organization_id', $this->org->id)->first();
        $this->assertNotNull($log);

        Event::assertDispatched(WarningIssued::class, function (WarningIssued $event) use ($log) {
            $payload = $event->payload;

            return $payload->source === 'early-warning'
                && $payload->sourceAlertId === (string) $log->id
                && $payload->organizationId === (int) $this->org->id
                && $payload->hazardType === 'flood'
                && $payload->severity === 'Severe'
                && $payload->urgency === 'Immediate'
                && $payload->certainty === 'Likely'
                && $payload->isExercise === false
                && $payload->advisoryText !== ''
                && is_array($payload->affected)
                && $payload->affected !== [];
        });

        Carbon::setTestNow();
    }
}
