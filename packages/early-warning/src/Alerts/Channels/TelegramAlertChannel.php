<?php

namespace Lumina\EarlyWarning\Alerts\Channels;

use Illuminate\Support\Facades\Http;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Support\AdvisoryCopy;
use RuntimeException;

class TelegramAlertChannel implements AlertChannel
{
    public function key(): string
    {
        return 'telegram';
    }

    public function send(AlertLog $log, array $settings): void
    {
        $token = (string) config('early-warning.channels.telegram.bot_token', '');
        if ($token === '') {
            throw new RuntimeException('EW_TELEGRAM_BOT_TOKEN is not configured.');
        }

        $chatIds = collect($settings['telegram_chat_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) || is_numeric($id))
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        if ($chatIds === []) {
            throw new RuntimeException('No telegram_chat_ids configured for early-warning.');
        }

        $text = $this->formatMessage($log);
        $timeout = max(3, (int) config('early-warning.channels.telegram.timeout_seconds', 10));

        foreach ($chatIds as $chatId) {
            $response = Http::connectTimeout(3)
                ->timeout($timeout)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Telegram send failed for chat {$chatId}: HTTP {$response->status()} ".$response->body(),
                );
            }
        }
    }

    protected function formatMessage(AlertLog $log): string
    {
        $p = $log->payload ?? [];
        $lines = [
            'Early Warning alert (advisory only)',
            'Basin: '.$log->basin_code,
            'Rule: '.($p['rule_name'] ?? '—'),
            'Metric: '.($p['metric'] ?? '—').' = '.($p['value'] ?? '—').' (threshold '.$p['threshold'].')',
            'Risk: '.($p['risk_level'] ?? '—').' / score '.($p['score'] ?? '—'),
            'Forecast hour: '.($p['forecast_at'] ?? '—'),
            ($p['advisory'] ?? AdvisoryCopy::message()),
        ];
        $authorityLine = AdvisoryCopy::authorityLine();
        if ($authorityLine !== null) {
            $lines[] = $authorityLine;
        }

        return implode("\n", $lines);
    }
}
