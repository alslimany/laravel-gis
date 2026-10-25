<?php

namespace Lumina\EarlyWarning\Alerts\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Support\AdvisoryCopy;
use RuntimeException;

/**
 * SMS delivery. Uses a configurable HTTP gateway (e.g. Twilio-compatible webhook)
 * when EW_SMS_WEBHOOK_URL is set; otherwise logs the message (dev-safe).
 */
class SmsAlertChannel implements AlertChannel
{
    public function key(): string
    {
        return 'sms';
    }

    public function send(AlertLog $log, array $settings): void
    {
        $phones = collect($settings['notify_phones'] ?? [])
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->values()
            ->all();

        if ($phones === []) {
            throw new RuntimeException('No notify_phones configured for early-warning SMS.');
        }

        $body = $this->formatBody($log);
        $webhook = (string) config('early-warning.channels.sms.webhook_url', '');

        if ($webhook === '') {
            Log::info('early-warning.alert.sms', [
                'organization_id' => $log->organization_id,
                'phones' => $phones,
                'body' => $body,
                'mode' => 'log-only',
            ]);

            return;
        }

        $timeout = max(3, (int) config('early-warning.channels.sms.timeout_seconds', 15));
        $response = Http::connectTimeout(3)
            ->timeout($timeout)
            ->asJson()
            ->post($webhook, [
                'to' => $phones,
                'message' => $body,
                'meta' => [
                    'plugin' => 'early-warning',
                    'alert_log_id' => $log->id,
                    'basin_code' => $log->basin_code,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('SMS webhook failed: HTTP '.$response->status());
        }
    }

    protected function formatBody(AlertLog $log): string
    {
        $p = $log->payload ?? [];

        $body = sprintf(
            'EW alert (advisory): %s @ %s — %s=%s (thr %s). Not an official warning.',
            $p['rule_name'] ?? 'Alert',
            $log->basin_code,
            $p['metric'] ?? 'metric',
            $p['value'] ?? '—',
            $p['threshold'] ?? '—',
        );
        $authorityLine = AdvisoryCopy::authorityLine();
        if ($authorityLine !== null) {
            $body .= ' '.$authorityLine;
        }

        return $body;
    }
}
