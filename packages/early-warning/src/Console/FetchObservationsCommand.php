<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Jobs\FetchObservationsJob;

class FetchObservationsCommand extends Command
{
    protected $signature = 'early-warning:fetch-observations
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Fetch IMERG/SMAP-class observations (NASA POWER bridge) into ew_observations';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Running FetchObservationsJob synchronously…');
            dispatch_sync(new FetchObservationsJob);
            $this->info('Done.');

            return self::SUCCESS;
        }

        FetchObservationsJob::dispatch();
        $this->info('FetchObservationsJob queued.');

        return self::SUCCESS;
    }
}
