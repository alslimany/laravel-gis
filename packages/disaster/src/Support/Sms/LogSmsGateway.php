<?php

namespace Lumina\Disaster\Support\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lumina\Disaster\Contracts\SmsGateway;

/**
 * Local/dev SMS gateway — logs only, never reaches a real provider.
 */
final class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(string $toRef, string $body, array $meta = []): SmsSendResult
    {
        $messageId = 'log-'.Str::uuid()->toString();

        Log::channel((string) config('disaster.sms.log_channel', 'stack'))
            ->info('disaster.sms.log_gateway', [
                'to_ref' => $toRef,
                'body' => $body,
                'meta' => $meta,
                'provider_message_id' => $messageId,
            ]);

        return SmsSendResult::success($messageId, 'sent');
    }
}
