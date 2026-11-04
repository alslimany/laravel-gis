<?php

namespace Lumina\Disaster\Support\Dissemination;

use Lumina\Disaster\Contracts\DisseminationChannelAdapter;
use Lumina\Disaster\Contracts\SmsGateway;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\SmsDelivery;
use Lumina\Disaster\Support\Enums\DisseminationStatus;

final class SmsChannelAdapter implements DisseminationChannelAdapter
{
    public function __construct(private SmsGateway $gateway) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function supports(DisseminationLog $log): bool
    {
        return $log->channel === 'sms' || $log->channel === 'cap';
    }

    public function send(DisseminationLog $log): void
    {
        if ($log->test_mode) {
            return;
        }

        $payload = is_array($log->payload) ? $log->payload : [];
        $recipients = $payload['sms_recipients'] ?? [];
        if (! is_array($recipients) || $recipients === []) {
            return;
        }

        $body = (string) ($payload['sms_body'] ?? $payload['headline'] ?? 'Disaster advisory');

        foreach ($recipients as $toRef) {
            $toRef = (string) $toRef;
            if ($toRef === '') {
                continue;
            }

            $delivery = SmsDelivery::query()->create([
                'organization_id' => $log->organization_id,
                'dissemination_log_id' => $log->id,
                'to_ref' => $toRef,
                'provider' => $this->gateway->name(),
                'status' => 'queued',
                'attempts' => 0,
            ]);

            $result = $this->gateway->send($toRef, $body, [
                'dissemination_log_id' => $log->id,
                'identifier' => $log->identifier,
                'test_mode' => false,
            ]);

            $delivery->forceFill([
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->ok ? $result->status : 'failed',
                'attempts' => 1,
                'last_error' => $result->error,
            ])->save();
        }

        $failed = SmsDelivery::query()
            ->where('dissemination_log_id', $log->id)
            ->where('status', 'failed')
            ->exists();

        if ($failed) {
            $log->forceFill([
                'status' => DisseminationStatus::Failed->value,
                'outcome' => 'sms_partial_or_failed',
            ])->save();
        }
    }
}
