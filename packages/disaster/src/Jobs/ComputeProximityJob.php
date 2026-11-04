<?php

namespace Lumina\Disaster\Jobs;

use App\Plugins\Contracts\OrganizationScopedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lumina\Disaster\Services\ExposureComputer;

class ComputeProximityJob implements OrganizationScopedJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public int $organizationId,
        public string $hazardKey,
        public float $hazardLatitude,
        public float $hazardLongitude,
        public ?float $radiusM = null,
        public string $scenarioName = '',
    ) {}

    public function handle(ExposureComputer $computer): void
    {
        $computer->compute(
            $this->organizationId,
            $this->hazardKey,
            $this->hazardLatitude,
            $this->hazardLongitude,
            $this->radiusM,
            $this->scenarioName,
        );
    }
}
