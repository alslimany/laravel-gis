<?php

namespace Lumina\Disaster\Jobs;

use App\Plugins\Contracts\OrganizationScopedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Services\CapDisseminationService;
use Lumina\Disaster\Support\Enums\DisseminationStatus;

class SendDisseminationJob implements OrganizationScopedJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $organizationId,
        public int $disseminationLogId,
    ) {}

    public function handle(CapDisseminationService $service): void
    {
        $log = DisseminationLog::query()
            ->forOrganization($this->organizationId)
            ->whereKey($this->disseminationLogId)
            ->first();

        if ($log === null) {
            return;
        }

        if (in_array($log->status, [
            DisseminationStatus::Sent->value,
            DisseminationStatus::Delivered->value,
            DisseminationStatus::Test->value,
        ], true) && $log->next_retry_at === null) {
            return;
        }

        $key = 'disaster-sms:'.$this->organizationId;
        $maxAttempts = (int) config('disaster.sms.rate_max_attempts', 30);
        $decaySeconds = (int) config('disaster.sms.rate_decay_seconds', 60);

        $allowed = RateLimiter::attempt(
            $key,
            $maxAttempts,
            function () use ($service, $log): void {
                $log->forceFill([
                    'attempt_count' => (int) $log->attempt_count + 1,
                    'next_retry_at' => null,
                ])->save();

                $service->fanOut($log->fresh() ?? $log);
            },
            $decaySeconds,
        );

        if (! $allowed) {
            $log->forceFill([
                'status' => DisseminationStatus::Queued->value,
                'outcome' => 'rate_limited',
                'next_retry_at' => now()->addSeconds($decaySeconds),
            ])->save();

            $this->release($decaySeconds);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $log = DisseminationLog::query()->find($this->disseminationLogId);
        if ($log === null) {
            return;
        }

        $log->forceFill([
            'status' => DisseminationStatus::Failed->value,
            'outcome' => 'job_failed',
            'next_retry_at' => now()->addMinutes(15),
        ])->save();
    }
}
