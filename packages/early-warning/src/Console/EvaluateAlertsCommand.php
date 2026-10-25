<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Jobs\EvaluateAlertsJob;

class EvaluateAlertsCommand extends Command
{
    protected $signature = 'early-warning:evaluate-alerts
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Evaluate org AlertRules and write AlertLog rows';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Running EvaluateAlertsJob synchronously…');
            dispatch_sync(new EvaluateAlertsJob);
            $this->info('Done.');

            return self::SUCCESS;
        }

        EvaluateAlertsJob::dispatch();
        $this->info('EvaluateAlertsJob queued.');

        return self::SUCCESS;
    }
}
