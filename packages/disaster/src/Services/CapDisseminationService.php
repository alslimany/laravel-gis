<?php

namespace Lumina\Disaster\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Lumina\Disaster\Contracts\DisseminationChannelAdapter;
use Lumina\Disaster\Jobs\SendDisseminationJob;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\MessageTemplate;
use Lumina\Disaster\Support\Cap\Cap12Builder;
use Lumina\Disaster\Support\Cap\Cap12Document;
use Lumina\Disaster\Support\Cap\Cap12Validator;
use Lumina\Disaster\Support\Enums\CapMsgType;
use Lumina\Disaster\Support\Enums\DisseminationStatus;
use Lumina\Disaster\Support\Enums\MessageTemplateStatus;

class CapDisseminationService
{
    /**
     * @param  iterable<DisseminationChannelAdapter>  $adapters
     */
    public function __construct(
        private Cap12Builder $builder,
        private Cap12Validator $validator,
        private iterable $adapters = [],
    ) {}

    /**
     * @param  array{headline?: string, description?: string, event?: string}  $input
     */
    public function draftCap(int $organizationId, array $input = []): DisseminationLog
    {
        $headline = $input['headline'] ?? 'Disaster advisory (draft)';
        $description = $input['description'] ?? 'Console CAP stub — not transmitted to external aggregators.';
        $event = $input['event'] ?? 'Disaster';
        $sent = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $identifier = 'disaster-draft-'.now()->timestamp.'-'.Str::lower(Str::random(6));
        $sender = (string) config('disaster.cap.sender', 'lumina-disaster');

        $document = new Cap12Document(
            identifier: $identifier,
            sender: $sender,
            sent: $sent,
            status: 'Draft',
            msgType: CapMsgType::Alert->value,
            scope: 'Public',
            infoBlocks: [[
                'language' => 'en-US',
                'category' => 'Other',
                'event' => $event,
                'urgency' => 'Unknown',
                'severity' => 'Unknown',
                'certainty' => 'Unknown',
                'headline' => $headline,
                'description' => $description,
            ]],
        );

        $capXml = $this->builder->build($document);

        return DisseminationLog::query()->create([
            'organization_id' => $organizationId,
            'channel' => 'cap',
            'status' => DisseminationStatus::Draft->value,
            'msg_type' => CapMsgType::Alert->value,
            'identifier' => $identifier,
            'test_mode' => true,
            'attempt_count' => 0,
            'payload' => [
                'cap_xml' => $capXml,
                'headline' => $headline,
                'description' => $description,
                'event' => $event,
                'sender' => $sender,
                'sent' => $sent,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Publish a full CAP 1.2 Alert (or Test) and queue fan-out.
     *
     * @param  array<string, mixed>  $input
     */
    public function publish(int $organizationId, array $input, bool $testMode = false): DisseminationLog
    {
        return $this->issue($organizationId, CapMsgType::Alert, null, $input, $testMode);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(int $organizationId, DisseminationLog $original, array $input, bool $testMode = false): DisseminationLog
    {
        $this->assertSameOrganization($organizationId, $original);

        return $this->issue($organizationId, CapMsgType::Update, $original, $input, $testMode);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function cancel(int $organizationId, DisseminationLog $original, array $input = [], bool $testMode = false): DisseminationLog
    {
        $this->assertSameOrganization($organizationId, $original);

        return $this->issue($organizationId, CapMsgType::Cancel, $original, $input, $testMode);
    }

    /**
     * @return array{sent: int, delivered: int, failed: int, queued: int}
     */
    public function deliveryReport(DisseminationLog $log): array
    {
        $base = [
            'sent' => 0,
            'delivered' => 0,
            'failed' => 0,
            'queued' => 0,
        ];

        foreach ($log->smsDeliveries as $delivery) {
            $status = $delivery->status;
            if (! array_key_exists($status, $base)) {
                continue;
            }
            $base[$status]++;
        }

        return $base;
    }

    public function fanOut(DisseminationLog $log): void
    {
        foreach ($this->adapters as $adapter) {
            if (! $adapter->supports($log)) {
                continue;
            }
            $adapter->send($log);
        }

        if ($log->fresh()?->status === DisseminationStatus::Queued->value) {
            $log->forceFill([
                'status' => $log->test_mode
                    ? DisseminationStatus::Test->value
                    : DisseminationStatus::Sent->value,
                'outcome' => $log->test_mode ? 'test_fanout_complete' : 'fanout_complete',
            ])->save();
        }
    }

    public function queueRetry(DisseminationLog $log): void
    {
        $attempts = (int) $log->attempt_count + 1;
        $delaySeconds = $this->backoffSeconds($attempts);

        $log->forceFill([
            'status' => DisseminationStatus::Queued->value,
            'attempt_count' => $attempts,
            'next_retry_at' => now()->addSeconds($delaySeconds),
            'outcome' => 'retry_scheduled',
        ])->save();

        SendDisseminationJob::dispatch((int) $log->organization_id, (int) $log->id)
            ->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * @return list<DisseminationLog>
     */
    public function retryDue(?int $organizationId = null): array
    {
        $query = DisseminationLog::query()
            ->where('channel', 'cap')
            ->whereIn('status', [
                DisseminationStatus::Queued->value,
                DisseminationStatus::Failed->value,
            ])
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit(100);

        if ($organizationId !== null) {
            $query->forOrganization($organizationId);
        }

        $logs = [];
        foreach ($query->get() as $log) {
            SendDisseminationJob::dispatch((int) $log->organization_id, (int) $log->id);
            $logs[] = $log;
        }

        return $logs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function issue(
        int $organizationId,
        CapMsgType $msgType,
        ?DisseminationLog $original,
        array $input,
        bool $testMode,
    ): DisseminationLog {
        $sender = (string) ($input['sender'] ?? config('disaster.cap.sender', 'lumina-disaster'));
        $sent = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $identifier = (string) ($input['identifier'] ?? $this->makeIdentifier($organizationId));
        $event = (string) ($input['event'] ?? 'Disaster');
        $category = (string) ($input['category'] ?? 'Other');
        $urgency = (string) ($input['urgency'] ?? 'Immediate');
        $severity = (string) ($input['severity'] ?? 'Severe');
        $certainty = (string) ($input['certainty'] ?? 'Likely');
        $expires = isset($input['expires'])
            ? (string) $input['expires']
            : now()->utc()->addHours(6)->format('Y-m-d\TH:i:s\Z');
        $hazardType = (string) ($input['hazard_type'] ?? 'general');
        /** @var list<array{0: float, 1: float}>|null $polygon */
        $polygon = $input['polygon'] ?? null;
        $areaDesc = isset($input['area_desc']) ? (string) $input['area_desc'] : null;

        $infoBlocks = $this->resolveInfoBlocks(
            $organizationId,
            $hazardType,
            $event,
            $category,
            $urgency,
            $severity,
            $certainty,
            $expires,
            $areaDesc,
            is_array($polygon) ? $polygon : null,
            $input,
        );
        $infoBlocks = self::arabicFirst($infoBlocks);

        $references = null;
        if ($original !== null) {
            $origPayload = is_array($original->payload) ? $original->payload : [];
            $origSender = (string) ($origPayload['sender'] ?? $sender);
            $origSent = (string) ($origPayload['sent'] ?? $original->created_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? $sent);
            $references = $this->builder->referencesLine(
                $origSender,
                (string) $original->identifier,
                $origSent,
            );
        }

        $capStatus = $testMode ? 'Test' : 'Actual';
        $document = new Cap12Document(
            identifier: $identifier,
            sender: $sender,
            sent: $sent,
            status: $capStatus,
            msgType: $msgType->value,
            scope: (string) ($input['scope'] ?? 'Public'),
            infoBlocks: $infoBlocks,
            references: $references,
        );

        $capXml = $this->builder->build($document);
        $errors = $this->validator->validate($capXml);
        if ($errors !== []) {
            throw new InvalidArgumentException('CAP validation failed: '.implode(' ', $errors));
        }

        $headline = $infoBlocks[0]['headline'] ?? $event;
        $description = $infoBlocks[0]['description'] ?? $event;

        $log = DisseminationLog::query()->create([
            'organization_id' => $organizationId,
            'channel' => 'cap',
            'status' => DisseminationStatus::Queued->value,
            'msg_type' => $msgType->value,
            'identifier' => $identifier,
            'references_identifier' => $original?->identifier,
            'outcome' => null,
            'attempt_count' => 0,
            'next_retry_at' => null,
            'test_mode' => $testMode,
            'payload' => [
                'cap_xml' => $capXml,
                'headline' => $headline,
                'description' => $description,
                'event' => $event,
                'sender' => $sender,
                'sent' => $sent,
                'hazard_type' => $hazardType,
                'sms_body' => (string) ($input['sms_body'] ?? $headline),
                'sms_recipients' => $input['sms_recipients'] ?? [],
                'fan_out_radio' => (bool) ($input['fan_out_radio'] ?? false),
                'info_blocks' => $infoBlocks,
            ],
            'created_at' => now(),
        ]);

        SendDisseminationJob::dispatch($organizationId, (int) $log->id);

        return $log;
    }

    /**
     * CAP-1: the Arabic <info> block comes first (primary language for NCMCD audiences);
     * other blocks keep their relative order.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function arabicFirst(array $blocks): array
    {
        $isAr = fn (array $b): bool => str_starts_with(strtolower((string) ($b['language'] ?? '')), 'ar');

        return [...array_values(array_filter($blocks, $isAr)), ...array_values(array_filter($blocks, fn ($b) => ! $isAr($b)))];
    }

    /**
     * @param  list<array{0: float, 1: float}>|null  $polygon
     * @param  array<string, mixed>  $input
     * @return list<array{
     *     language: string,
     *     category: string,
     *     event: string,
     *     urgency: string,
     *     severity: string,
     *     certainty: string,
     *     headline: string,
     *     description: string,
     *     expires?: string|null,
     *     area_desc?: string|null,
     *     polygon?: list<array{0: float, 1: float}>|null
     * }>
     */
    private function resolveInfoBlocks(
        int $organizationId,
        string $hazardType,
        string $event,
        string $category,
        string $urgency,
        string $severity,
        string $certainty,
        string $expires,
        ?string $areaDesc,
        ?array $polygon,
        array $input,
    ): array {
        if (isset($input['info']) && is_array($input['info']) && $input['info'] !== []) {
            return array_values($input['info']);
        }

        $blocks = [];
        foreach (['ar', 'en'] as $language) {
            $template = MessageTemplate::query()
                ->forOrganization($organizationId)
                ->where('hazard_type', $hazardType)
                ->where('language', $language)
                ->where('channel', 'cap')
                ->where('status', MessageTemplateStatus::Approved->value)
                ->orderByDesc('approved_at')
                ->first();

            $headline = $template?->headline
                ?? (string) ($input['headline_'.$language] ?? $input['headline'] ?? $event);
            $description = $template?->body
                ?? (string) ($input['description_'.$language] ?? $input['description'] ?? $event);

            $blocks[] = [
                'language' => $language === 'ar' ? 'ar' : 'en-US',
                'category' => $category,
                'event' => $event,
                'urgency' => $urgency,
                'severity' => $severity,
                'certainty' => $certainty,
                'expires' => $expires,
                'headline' => $headline,
                'description' => $description,
                'area_desc' => $areaDesc,
                'polygon' => $polygon,
            ];
        }

        return $blocks;
    }

    private function makeIdentifier(int $organizationId): string
    {
        return sprintf(
            'lumina-dm-%d-%s-%s',
            $organizationId,
            now()->utc()->format('YmdHis'),
            Str::lower(Str::random(8)),
        );
    }

    private function assertSameOrganization(int $organizationId, DisseminationLog $log): void
    {
        if ((int) $log->organization_id !== $organizationId) {
            throw new InvalidArgumentException('Dissemination log belongs to another organization.');
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        $schedule = [60, 300, 900, 1800, 3600];

        return $schedule[min($attempt - 1, count($schedule) - 1)];
    }
}
