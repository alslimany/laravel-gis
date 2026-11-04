<?php

namespace Lumina\Disaster\Support\Sms;

use Lumina\Disaster\Contracts\SmsGateway;

/**
 * Stub provider that records no side effects (tests / disabled SMS).
 */
final class NullSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'null';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(string $toRef, string $body, array $meta = []): SmsSendResult
    {
        return SmsSendResult::success('null-'.md5($toRef.$body), 'sent');
    }
}
