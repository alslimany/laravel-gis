<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Jobs\FetchForecastsJob;

class FetchForecastsCommand extends Command
{
    protected $signature = 'early-warning:fetch-forecasts
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Fetch Open-Meteo hourly forecasts for all ew_basins outlets (#60)';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Running FetchForecastsJob synchronously…');
            dispatch_sync(new FetchForecastsJob);
            $this->info('Done.');

            return self::SUCCESS;
        }

        FetchForecastsJob::dispatch();
        $this->info('FetchForecastsJob queued.');

        return self::SUCCESS;
    }
}
