<?php

namespace Lumina\Disaster\Support\Sms;

use Lumina\Disaster\Contracts\SmsGateway;

/**
 * Test double that records send attempts without calling an external network.
 */
final class RecordingSmsGateway implements SmsGateway
{
    /** @var list<array{to_ref: string, body: string, meta: array<string, mixed>}> */
    public array $sent = [];

    public function name(): string
    {
        return 'recording';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(string $toRef, string $body, array $meta = []): SmsSendResult
    {
        $this->sent[] = [
            'to_ref' => $toRef,
            'body' => $body,
            'meta' => $meta,
        ];

        return SmsSendResult::success('rec-'.count($this->sent), 'sent');
    }
}
