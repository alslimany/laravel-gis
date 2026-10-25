<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Jobs\DispatchAlertsJob;

class DispatchAlertsCommand extends Command
{
    protected $signature = 'early-warning:dispatch-alerts
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Send pending AlertLog rows via configured channels';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Running DispatchAlertsJob synchronously…');
            dispatch_sync(new DispatchAlertsJob);
            $this->info('Done.');

            return self::SUCCESS;
        }

        DispatchAlertsJob::dispatch();
        $this->info('DispatchAlertsJob queued.');

        return self::SUCCESS;
    }
}
