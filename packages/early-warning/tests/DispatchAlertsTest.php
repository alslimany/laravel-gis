<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Lumina\EarlyWarning\Alerts\AlertDispatcher;
use Lumina\EarlyWarning\Jobs\DispatchAlertsJob;
use Lumina\EarlyWarning\Mail\FloodAlertMail;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** Phase 2: DispatchAlerts via log / email / Telegram. */
class DispatchAlertsTest extends TestCase
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
            'version' => '0.6.0',
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
            'settings' => [
                'notify_emails' => ['ops@example.com'],
                'telegram_chat_ids' => ['12345'],
            ],
        ])->save();
        app(PluginManager::class)->forgetOrganization($this->org->id);
        app(PluginManager::class)->forgetInstalled();
        app(PluginManager::class)->registerPermissionGates();
    }

    public function test_dispatch_skips_logs_when_plugin_disabled_for_org(): void
    {
        OrganizationPlugin::query()
            ->where('organization_id', $this->org->id)
            ->update(['enabled' => false]);
        app(PluginManager::class)->forgetOrganization($this->org->id);

        $log = $this->makeLog('log');
        $sent = app(AlertDispatcher::class)->dispatchPending();

        $this->assertSame(0, $sent);
        $this->assertSame('triggered', $log->fresh()->status);
        $this->assertNull($log->fresh()->sent_at);
    }

    public function test_log_channel_marks_sent(): void
    {
        $log = $this->makeLog('log');

        $sent = app(AlertDispatcher::class)->dispatchPending($this->org->id);

        $this->assertSame(1, $sent);
        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_email_channel_sends_mail(): void
    {
        Mail::fake();
        $log = $this->makeLog('email');

        app(AlertDispatcher::class)->dispatchOne($log);

        Mail::assertSent(FloodAlertMail::class, function (FloodAlertMail $mail) {
            return $mail->hasTo('ops@example.com');
        });
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_telegram_channel_posts_to_bot_api(): void
    {
        config(['early-warning.channels.telegram.bot_token' => 'test-token']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $log = $this->makeLog('telegram');
        app(AlertDispatcher::class)->dispatchOne($log);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
            && $request['chat_id'] === '12345');
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_missing_email_recipients_marks_failed(): void
    {
        $row = OrganizationPlugin::query()
            ->where('organization_id', $this->org->id)
            ->where('plugin_key', 'early-warning')
            ->firstOrFail();
        $row->settings = ['notify_emails' => [], 'telegram_chat_ids' => []];
        $row->save();

        $log = $this->makeLog('email');
        app(AlertDispatcher::class)->dispatchOne($log);

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->sent_at);
        $this->assertArrayHasKey('dispatch_error', $log->payload);
    }

    public function test_update_channel_settings_via_http(): void
    {
        $this->actingAs($this->user)
            ->put('/x/early-warning/alert-rules/channels', [
                'notify_emails' => 'a@example.com, b@example.com',
                'telegram_chat_ids' => '99, 100',
            ])
            ->assertRedirect(route('early-warning.alert-rules.index'));

        $settings = OrganizationPlugin::query()
            ->where('organization_id', $this->org->id)
            ->value('settings');

        $this->assertSame(['a@example.com', 'b@example.com'], $settings['notify_emails']);
        $this->assertSame(['99', '100'], $settings['telegram_chat_ids']);
    }

    public function test_dispatch_alerts_job_and_command(): void
    {
        $this->makeLog('log');

        (new DispatchAlertsJob)->handle(
            app(AlertDispatcher::class),
            app(PluginManager::class),
        );

        $this->assertSame(1, AlertLog::query()->where('status', 'sent')->count());
        $this->artisan('early-warning:dispatch-alerts', ['--sync' => true])->assertExitCode(0);
    }

    protected function makeLog(string $channel): AlertLog
    {
        $rule = AlertRule::query()->create([
            'organization_id' => $this->org->id,
            'name' => 'Dispatch test',
            'metric' => 'precip_6h_mm',
            'operator' => '>=',
            'threshold' => 20,
            'channels' => [$channel],
            'enabled' => true,
        ]);

        return AlertLog::query()->create([
            'organization_id' => $this->org->id,
            'alert_rule_id' => $rule->id,
            'basin_code' => 'TRP-HIRA',
            'channel' => $channel,
            'status' => 'triggered',
            'payload' => [
                'rule_name' => 'Dispatch test',
                'metric' => 'precip_6h_mm',
                'value' => 25,
                'threshold' => 20,
                'advisory' => 'Forecast-based decision support only — not an official warning.',
            ],
            'sent_at' => null,
        ]);
    }
}
