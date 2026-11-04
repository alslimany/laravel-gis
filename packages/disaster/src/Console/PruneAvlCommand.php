<?php

namespace Lumina\Disaster\Console;

use Illuminate\Console\Command;
use Lumina\Disaster\Jobs\PruneAvlJob;

class PruneAvlCommand extends Command
{
    protected $signature = 'disaster:prune-avl {--sync : Run inline instead of queue}';

    protected $description = 'Delete AVL ping history older than retention_avl_days';

    public function handle(): int
    {
        if ($this->option('sync')) {
            dispatch_sync(new PruneAvlJob);
            $this->info('AVL ping prune completed.');

            return self::SUCCESS;
        }

        PruneAvlJob::dispatch();
        $this->info('PruneAvlJob queued.');

        return self::SUCCESS;
    }
}
