<?php

namespace Lumina\EarlyWarning\Alerts\Channels;

use Illuminate\Support\Facades\Mail;
use Lumina\EarlyWarning\Mail\FloodAlertMail;
use Lumina\EarlyWarning\Models\AlertLog;
use RuntimeException;

class EmailAlertChannel implements AlertChannel
{
    public function key(): string
    {
        return 'email';
    }

    public function send(AlertLog $log, array $settings): void
    {
        $emails = collect($settings['notify_emails'] ?? [])
            ->filter(fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();

        if ($emails === []) {
            throw new RuntimeException('No notify_emails configured for early-warning.');
        }

        Mail::to($emails)->send(new FloodAlertMail($log));
    }
}
