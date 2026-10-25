<?php

namespace Lumina\EarlyWarning\Alerts\Channels;

use Lumina\EarlyWarning\Models\AlertLog;

interface AlertChannel
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $settings  org plugin settings
     */
    public function send(AlertLog $log, array $settings): void;
}
