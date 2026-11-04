<?php

namespace Lumina\Disaster\Contracts;

use Lumina\Disaster\Support\Sms\SmsSendResult;

interface SmsGateway
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(string $toRef, string $body, array $meta = []): SmsSendResult;

    public function name(): string;
}
