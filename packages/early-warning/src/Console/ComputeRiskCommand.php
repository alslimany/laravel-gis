<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Jobs\ComputeRiskJob;

class ComputeRiskCommand extends Command
{
    protected $signature = 'early-warning:compute-risk
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Compute basin flood-risk assessments from forecasts (#61)';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Running ComputeRiskJob synchronously…');
            dispatch_sync(new ComputeRiskJob);
            $this->info('Done.');

            return self::SUCCESS;
        }

        ComputeRiskJob::dispatch();
        $this->info('ComputeRiskJob queued.');

        return self::SUCCESS;
    }
}
