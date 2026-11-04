<?php

namespace Lumina\Disaster\Support\Dissemination;

use Illuminate\Support\Facades\Cache;
use Lumina\Disaster\Contracts\DisseminationChannelAdapter;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Support\Enums\DisseminationStatus;

/**
 * CAP channel — validates payload already on the log and refreshes public cache.
 */
final class CapChannelAdapter implements DisseminationChannelAdapter
{
    public function channel(): string
    {
        return 'cap';
    }

    public function supports(DisseminationLog $log): bool
    {
        return $log->channel === 'cap';
    }

    public function send(DisseminationLog $log): void
    {
        $payload = is_array($log->payload) ? $log->payload : [];
        $xml = (string) ($payload['cap_xml'] ?? '');

        if ($xml === '') {
            $log->forceFill([
                'status' => DisseminationStatus::Failed->value,
                'outcome' => 'missing_cap_xml',
            ])->save();

            return;
        }

        $status = $log->test_mode
            ? DisseminationStatus::Test->value
            : DisseminationStatus::Sent->value;

        $log->forceFill([
            'status' => $status,
            'outcome' => $log->test_mode ? 'test_cap_logged' : 'cap_published',
        ])->save();

        if (! $log->test_mode) {
            Cache::forget($this->cacheKey((int) $log->organization_id));
            Cache::put(
                $this->cacheKey((int) $log->organization_id),
                [
                    'identifier' => $log->identifier,
                    'msg_type' => $log->msg_type,
                    'cap_xml' => $xml,
                    'sent_at' => now()->utc()->toIso8601String(),
                ],
                now()->addSeconds((int) config('disaster.cap.public_cache_seconds', 60)),
            );
        }
    }

    public static function cacheKey(int $organizationId): string
    {
        return "disaster:public:cap:{$organizationId}";
    }
}
