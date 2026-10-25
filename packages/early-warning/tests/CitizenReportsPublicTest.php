<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\EarlyWarning\Alerts\AlertDispatcher;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\CitizenReport;
use Lumina\EarlyWarning\Models\PublicToken;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Phase 3: citizen reports, public token feed, SMS channel. */
class CitizenReportsPublicTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private string $plainToken;

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
            'version' => '0.9.0',
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
            'settings' => ['notify_phones' => ['+218910000000']],
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();
        app(PluginManager::class)->registerPermissionGates();

        Basin::query()->create([
            'basin_code' => 'TRP-HIRA',
            'name' => 'Wadi Al-Hira',
            'region' => 'tripoli',
            'outlet_lat' => 32.83,
            'outlet_lon' => 13.03,
        ]);

        $issued = PublicToken::issue($this->org->id, 'test');
        $this->plainToken = $issued['plain'];
    }

    public function test_public_feed_json_and_inertia(): void
    {
        $this->getJson('/ew/public/'.$this->plainToken.'/feed')
            ->assertOk()
            ->assertJsonPath('plugin', 'early-warning')
            ->assertJsonStructure(['basins', 'reports', 'advisory']);

        $this->get('/ew/public/'.$this->plainToken)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EarlyWarning/Public')
                ->has('basins')
                ->has('can_report')
            );
    }

    public function test_invalid_token_is_404(): void
    {
        $this->getJson('/ew/public/not-a-real-token/feed')->assertNotFound();
    }

    public function test_public_report_submission(): void
    {
        Storage::fake('public');

        $this->post('/ew/public/'.$this->plainToken.'/reports', [
            'latitude' => 32.831,
            'longitude' => 13.035,
            'note' => 'Street flooding near outlet',
            'photo' => UploadedFile::fake()->image('flood.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $report = CitizenReport::query()->first();
        $this->assertNotNull($report);
        $this->assertSame('pending', $report->status);
        $this->assertSame($this->org->id, $report->organization_id);
        Storage::disk('public')->assertExists($report->photo_path);
    }

    public function test_moderation_and_console_index(): void
    {
        $report = CitizenReport::query()->create([
            'organization_id' => $this->org->id,
            'latitude' => 32.8,
            'longitude' => 13.0,
            'note' => 'test',
            'status' => 'pending',
            'source' => 'public',
            'reported_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->put('/x/early-warning/citizen-reports/'.$report->id, ['status' => 'approved'])
            ->assertRedirect(route('early-warning.citizen-reports.index'));

        $this->assertSame('approved', $report->fresh()->status);

        $this->actingAs($this->user)
            ->get('/x/early-warning/citizen-reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EarlyWarning/CitizenReports')
                ->has('reports')
                ->has('tokens')
            );
    }

    public function test_sms_channel_logs_when_webhook_missing(): void
    {
        Log::spy();

        $rule = AlertRule::query()->create([
            'organization_id' => $this->org->id,
            'name' => 'SMS test',
            'metric' => 'precip_6h_mm',
            'operator' => '>=',
            'threshold' => 1,
            'channels' => ['sms'],
            'enabled' => true,
        ]);

        $log = AlertLog::query()->create([
            'organization_id' => $this->org->id,
            'alert_rule_id' => $rule->id,
            'basin_code' => 'TRP-HIRA',
            'channel' => 'sms',
            'status' => 'triggered',
            'payload' => [
                'rule_name' => 'SMS test',
                'metric' => 'precip_6h_mm',
                'value' => 5,
                'threshold' => 1,
            ],
        ]);

        app(AlertDispatcher::class)->dispatchOne($log);

        $this->assertSame('sent', $log->fresh()->status);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => $msg === 'early-warning.alert.sms');
    }

    public function test_issue_token_shows_plain_once(): void
    {
        $this->actingAs($this->user)
            ->post('/x/early-warning/public-tokens', ['label' => 'Ops'])
            ->assertRedirect(route('early-warning.citizen-reports.index'))
            ->assertSessionHas('ew_plain_token');
    }
}
