<?php

namespace Lumina\Disaster\Console;

use Illuminate\Console\Command;
use Lumina\Disaster\Jobs\RecomputeEvacRoutesJob;
use Lumina\Disaster\Models\RoadNetworkEdge;
use Lumina\Disaster\Services\EvacuationRoutingService;

class RecomputeEvacRoutesCommand extends Command
{
    protected $signature = 'disaster:recompute-evac-routes
        {organization? : Organization id (required unless --all)}
        {--order= : Optional evacuation order id}
        {--sync : Run inline instead of queue}
        {--all : Recompute for every organization that has road edges}';

    protected $description = 'Recompute closure-aware evacuation corridors and capacity-aware shelter assignments';

    public function handle(EvacuationRoutingService $service): int
    {
        $orderId = $this->option('order') !== null ? (int) $this->option('order') : null;

        $organizationIds = $this->resolveOrganizationIds();
        if ($organizationIds === []) {
            $this->error('Provide {organization} or --all.');

            return self::FAILURE;
        }

        foreach ($organizationIds as $organizationId) {
            $job = new RecomputeEvacRoutesJob($organizationId, $orderId);

            if ($this->option('sync')) {
                $run = $service->recompute($organizationId, $orderId);
                $this->info(sprintf(
                    'Org %d: run #%d status=%s duration_ms=%s assigned=%s',
                    $organizationId,
                    $run->id,
                    $run->status,
                    data_get($run->metrics, 'duration_ms', '?'),
                    data_get($run->metrics, 'assigned', '?'),
                ));

                continue;
            }

            RecomputeEvacRoutesJob::dispatch($organizationId, $orderId);
            $this->info("RecomputeEvacRoutesJob queued for organization {$organizationId}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveOrganizationIds(): array
    {
        if ($this->option('all')) {
            return RoadNetworkEdge::query()
                ->distinct()
                ->orderBy('organization_id')
                ->pluck('organization_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $arg = $this->argument('organization');
        if ($arg === null || $arg === '') {
            return [];
        }

        return [(int) $arg];
    }
}
