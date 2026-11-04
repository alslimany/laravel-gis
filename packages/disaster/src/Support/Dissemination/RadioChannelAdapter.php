<?php

namespace Lumina\Disaster\Support\Dissemination;

use Illuminate\Support\Facades\Log;
use Lumina\Disaster\Contracts\DisseminationChannelAdapter;
use Lumina\Disaster\Models\DisseminationLog;

/**
 * Placeholder radio/partner feed adapter — logs intent for later wiring.
 */
final class RadioChannelAdapter implements DisseminationChannelAdapter
{
    public function channel(): string
    {
        return 'radio';
    }

    public function supports(DisseminationLog $log): bool
    {
        return $log->channel === 'radio'
            || (is_array($log->payload) && ! empty($log->payload['fan_out_radio']));
    }

    public function send(DisseminationLog $log): void
    {
        if ($log->test_mode) {
            return;
        }

        Log::channel((string) config('disaster.sms.log_channel', 'stack'))
            ->info('disaster.radio.stub', [
                'dissemination_log_id' => $log->id,
                'identifier' => $log->identifier,
                'organization_id' => $log->organization_id,
            ]);
    }
}
