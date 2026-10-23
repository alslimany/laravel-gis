<?php

namespace App\Console\Commands;

use App\Plugins\PluginLifecycle;
use App\Plugins\PluginLifecycleException;
use Illuminate\Console\Command;

/**
 * ADR-0001 D4/D15: plugin lifecycle runs from the CLI (or a queued job), never in a web request.
 */
class PluginInstall extends Command
{
    protected $signature = 'plugin:install {key} {--actor= : Email of the super admin performing this (optional: omitted = run from the CLI, audited as actor=cli)}';

    protected $description = 'Install a plugin (super admin; audited in plugin_events)';

    public function handle(PluginLifecycle $lifecycle): int
    {
        try {
            $row = $lifecycle->install($this->argument('key'), PluginLifecycle::actorFromEmail($this->option('actor')));
        } catch (PluginLifecycleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$row->plugin_key} {$row->version}: {$row->status}.");

        return self::SUCCESS;
    }
}
