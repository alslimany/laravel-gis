<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Jobs\ComputeExposureJob;

class ComputeExposureCommand extends Command
{
    protected $signature = 'early-warning:compute-exposure
        {--basin= : Limit to one basin_code}
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Compute OSM building/road exposure summaries per basin';

    public function handle(): int
    {
        $basin = $this->option('basin');
        $job = new ComputeExposureJob(
            is_string($basin) && $basin !== '' ? $basin : null,
        );

        if ($this->option('sync')) {
            $this->info('Running ComputeExposureJob synchronously…');
            dispatch_sync($job);
            $this->info('Done.');

            return self::SUCCESS;
        }

        ComputeExposureJob::dispatch($job->basinCode);
        $this->info('ComputeExposureJob queued.');

        return self::SUCCESS;
    }
}
