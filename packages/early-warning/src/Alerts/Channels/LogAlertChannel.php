<?php

namespace Lumina\EarlyWarning\Alerts\Channels;

use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Models\AlertLog;

/** Audit channel: records to application log (always available). */
class LogAlertChannel implements AlertChannel
{
    public function key(): string
    {
        return 'log';
    }

    public function send(AlertLog $log, array $settings): void
    {
        Log::info('early-warning.alert', [
            'organization_id' => $log->organization_id,
            'basin_code' => $log->basin_code,
            'rule' => $log->payload['rule_name'] ?? null,
            'metric' => $log->payload['metric'] ?? null,
            'value' => $log->payload['value'] ?? null,
            'threshold' => $log->payload['threshold'] ?? null,
            'advisory' => $log->payload['advisory'] ?? null,
        ]);
    }
}
