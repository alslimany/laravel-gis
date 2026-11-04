<?php

namespace Lumina\Disaster\Jobs;

use App\Plugins\Contracts\OrganizationScopedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lumina\Disaster\Services\EvacuationRoutingService;

/**
 * Recompute closure-aware, capacity-aware evacuation routes for one organization.
 */
class RecomputeEvacRoutesJob implements OrganizationScopedJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    /**
     * @param  list<array{
     *     zone_key: string,
     *     population: int,
     *     lat: float,
     *     lon: float,
     *     vulnerable?: bool,
     *     priority?: int
     * }>  $zones
     */
    public function __construct(
        public int $organizationId,
        public ?int $evacuationOrderId = null,
        public array $zones = [],
        public ?int $createdBy = null,
    ) {}

    public function handle(EvacuationRoutingService $service): void
    {
        $service->recompute(
            $this->organizationId,
            $this->evacuationOrderId,
            $this->zones,
            $this->createdBy,
        );
    }
}
