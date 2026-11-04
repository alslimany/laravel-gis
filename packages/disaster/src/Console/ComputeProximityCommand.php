<?php

namespace Lumina\Disaster\Console;

use Illuminate\Console\Command;
use Lumina\Disaster\Jobs\ComputeProximityJob;
use Lumina\Disaster\Services\ExposureComputer;

class ComputeProximityCommand extends Command
{
    protected $signature = 'disaster:compute-proximity
        {organization_id : Organization id}
        {hazard_key : Hazard identifier}
        {latitude : Hazard anchor latitude}
        {longitude : Hazard anchor longitude}
        {--radius= : Radius in meters}
        {--scenario= : Optional scenario name}
        {--sync : Run inline instead of queue}';

    protected $description = 'Compute asset exposure (PostGIS) or legacy haversine summaries for a hazard anchor';

    public function handle(ExposureComputer $computer): int
    {
        $job = new ComputeProximityJob(
            (int) $this->argument('organization_id'),
            (string) $this->argument('hazard_key'),
            (float) $this->argument('latitude'),
            (float) $this->argument('longitude'),
            $this->option('radius') !== null ? (float) $this->option('radius') : null,
            (string) ($this->option('scenario') ?? ''),
        );

        if ($this->option('sync')) {
            dispatch_sync($job);
            $this->info('Exposure summaries updated (engine: '.($computer->usesPostgis() ? 'postgis' : 'legacy_haversine_sqlite').').');

            return self::SUCCESS;
        }

        ComputeProximityJob::dispatch(
            $job->organizationId,
            $job->hazardKey,
            $job->hazardLatitude,
            $job->hazardLongitude,
            $job->radiusM,
            $job->scenarioName,
        );
        $this->info('ComputeProximityJob queued.');

        return self::SUCCESS;
    }
}
