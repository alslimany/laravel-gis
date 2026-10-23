<?php

namespace App\Console\Commands;

use App\Plugins\PluginLifecycle;
use App\Plugins\PluginLifecycleException;
use Illuminate\Console\Command;

/**
 * ADR-0001 §8: recover a failed install/upgrade (status failed/installing/upgrading).
 */
class PluginRollback extends Command
{
    protected $signature = 'plugin:rollback {key} {--actor= : Email of the super admin performing this (optional: omitted = run from the CLI, audited as actor=cli)}';

    protected $description = 'Roll back a failed plugin install/upgrade: undo its batches, restore the backup (audited)';

    public function handle(PluginLifecycle $lifecycle): int
    {
        try {
            $row = $lifecycle->rollback($this->argument('key'), PluginLifecycle::actorFromEmail($this->option('actor')));
        } catch (PluginLifecycleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$this->argument('key')} rolled back: batches ".(implode(', ', $row['rolled_back_batches']) ?: 'none').'; backup restored: '.($row['restored_backup'] ? 'yes' : 'no').'.');

        return self::SUCCESS;
    }
}
