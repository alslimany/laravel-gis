<?php

namespace App\Console\Commands;

use App\Plugins\PluginLifecycle;
use App\Plugins\PluginLifecycleException;
use Illuminate\Console\Command;

/**
 * ADR-0001 D4/D15: plugin lifecycle runs from the CLI (or a queued job), never in a web request.
 */
class PluginUpgrade extends Command
{
    protected $signature = 'plugin:upgrade {key} {--actor= : Email of the super admin performing this (optional: omitted = run from the CLI, audited as actor=cli)}
        {--force : Run even though a failed operation is pending (keeps the original pending record)}';

    protected $description = 'Upgrade a plugin (super admin; audited in plugin_events)';

    public function handle(PluginLifecycle $lifecycle): int
    {
        try {
            $row = $lifecycle->upgrade($this->argument('key'), PluginLifecycle::actorFromEmail($this->option('actor')), (bool) $this->option('force'));
        } catch (PluginLifecycleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$row->plugin_key} {$row->version}: {$row->status}.");

        return self::SUCCESS;
    }
}
