<?php

namespace Lumina\Disaster\Jobs;

use App\Plugins\Contracts\OrganizationScopedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lumina\Disaster\Services\ExposureComputer;

/**
 * Heavy PostGIS zone / scenario exposure + risk index compute.
 */
class ComputeExposureJob implements OrganizationScopedJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public int $organizationId,
        public string $hazardKey,
        public ?int $hazardZoneLayerId = null,
        public ?int $hazardFeatureId = null,
        public ?float $hazardLatitude = null,
        public ?float $hazardLongitude = null,
        public ?float $radiusM = null,
        public ?float $hazardLevel = null,
        public string $scenarioName = '',
        public ?int $damSiteId = null,
    ) {}

    public function handle(ExposureComputer $computer): void
    {
        if ($this->damSiteId !== null) {
            $computer->computeDamFailureScenario(
                $this->organizationId,
                $this->damSiteId,
                $this->scenarioName !== '' ? $this->scenarioName : 'dam-failure',
                $this->hazardLevel,
            );

            return;
        }

        $computer->computeZone(
            organizationId: $this->organizationId,
            hazardKey: $this->hazardKey,
            hazardZoneLayerId: $this->hazardZoneLayerId,
            hazardFeatureId: $this->hazardFeatureId,
            hazardLatitude: $this->hazardLatitude,
            hazardLongitude: $this->hazardLongitude,
            radiusM: $this->radiusM,
            hazardLevel: $this->hazardLevel,
            scenarioName: $this->scenarioName,
            engineNote: 'queued_zone_compute',
        );
    }
}
