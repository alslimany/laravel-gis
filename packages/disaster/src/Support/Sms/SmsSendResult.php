<?php

namespace Lumina\Disaster\Support\Sms;

final class SmsSendResult
{
    public function __construct(
        public bool $ok,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public string $status = 'sent',
    ) {}

    public static function success(string $providerMessageId, string $status = 'sent'): self
    {
        return new self(true, $providerMessageId, null, $status);
    }

    public static function failure(string $error, string $status = 'failed'): self
    {
        return new self(false, null, $error, $status);
    }
}
