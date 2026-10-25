<?php

namespace Lumina\EarlyWarning\Alerts;

use App\Models\OrganizationPlugin;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Alerts\Channels\AlertChannel;
use Lumina\EarlyWarning\Alerts\Channels\EmailAlertChannel;
use Lumina\EarlyWarning\Alerts\Channels\LogAlertChannel;
use Lumina\EarlyWarning\Alerts\Channels\SmsAlertChannel;
use Lumina\EarlyWarning\Alerts\Channels\TelegramAlertChannel;
use Lumina\EarlyWarning\Models\AlertLog;
use Throwable;

/** Delivers pending AlertLog rows through configured channels. */
class AlertDispatcher
{
    /** @var array<string, AlertChannel> */
    private array $channels;

    public function __construct()
    {
        $drivers = [
            new LogAlertChannel,
            new EmailAlertChannel,
            new TelegramAlertChannel,
            new SmsAlertChannel,
        ];
        $this->channels = [];
        foreach ($drivers as $driver) {
            $this->channels[$driver->key()] = $driver;
        }
    }

    /**
     * @return int number of logs marked sent
     */
    public function dispatchPending(?int $organizationId = null): int
    {
        $query = AlertLog::query()
            ->whereNull('sent_at')
            ->whereIn('status', ['triggered', 'pending'])
            ->orderBy('id')
            ->limit(200);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        } else {
            // Only orgs that still have the plugin enabled (ADR §5 — disable skips delivery).
            $enabledOrgIds = app(PluginManager::class)->enabledOrganizations('early-warning');
            if ($enabledOrgIds->isEmpty()) {
                return 0;
            }
            $query->whereIn('organization_id', $enabledOrgIds->all());
        }

        $sent = 0;
        foreach ($query->get() as $log) {
            if ($this->dispatchOne($log)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function dispatchOne(AlertLog $log): bool
    {
        $organizationId = (int) $log->organization_id;
        if (! app(PluginManager::class)->isEnabledFor('early-warning', $organizationId)) {
            Log::info('early-warning.alert.dispatch_skipped_disabled', [
                'alert_log_id' => $log->id,
                'organization_id' => $organizationId,
            ]);

            return false;
        }

        $channelKey = $log->channel ?: 'log';
        $driver = $this->channels[$channelKey] ?? null;
        if (! $driver) {
            $this->markFailed($log, "Unknown channel [{$channelKey}]");

            return false;
        }

        $settings = $this->orgSettings($organizationId);

        try {
            $driver->send($log, $settings);
            $log->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
            ])->save();

            return true;
        } catch (Throwable $e) {
            $this->markFailed($log, $e->getMessage());
            Log::warning('early-warning.alert.dispatch_failed', [
                'alert_log_id' => $log->id,
                'channel' => $channelKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function orgSettings(int $organizationId): array
    {
        $row = OrganizationPlugin::query()
            ->where('organization_id', $organizationId)
            ->where('plugin_key', 'early-warning')
            ->first();

        return is_array($row?->settings) ? $row->settings : [];
    }

    protected function markFailed(AlertLog $log, string $error): void
    {
        $payload = $log->payload ?? [];
        $payload['dispatch_error'] = $error;
        $log->forceFill([
            'status' => 'failed',
            'payload' => $payload,
        ])->save();
    }
}
