<?php

namespace App\Console\Commands;

use App\Plugins\PluginLifecycle;
use App\Plugins\PluginLifecycleException;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;

/**
 * ADR-0001 §6: destructive, super admin only. Requires the plugin to be
 * uninstalled and a typed-key confirmation (interactive, or --confirm=<key>).
 */
class PluginPurge extends Command
{
    protected $signature = 'plugin:purge {key}
        {--actor= : Email of the super admin performing this (optional: omitted = run from the CLI, audited as actor=cli)}
        {--confirm= : Type the plugin key to confirm (non-interactive)}';

    protected $description = 'Permanently drop an uninstalled plugin\'s tables and layers (audited)';

    public function handle(PluginLifecycle $lifecycle, PluginManager $plugins): int
    {
        $key = $this->argument('key');
        $manifest = $plugins->manifest($key);

        // TODO(#54 follow-up): purge should also remove the plugin's GeoServer
        // publications (layers/styles/workspaces); tracked in a follow-up ticket.
        $this->warn('Note: GeoServer publications are NOT removed by purge yet; this is coming in a follow-up ticket.');

        if ($manifest) {
            $this->warn('This drops: '.(implode(', ', $lifecycle->pluginTables($manifest)) ?: '(no tables)'));
            $layers = $lifecycle->pluginLayers($key);
            if ($layers->isNotEmpty()) {
                $this->warn('And deletes layers: '.$layers->pluck('name')->implode(', '));
            }
        }

        $typed = $this->option('confirm') ?? $this->ask("Type \"{$key}\" to purge");
        if ($typed !== $key) {
            $this->error('Confirmation did not match; nothing was purged.');

            return self::FAILURE;
        }

        try {
            $summary = $lifecycle->purge($key, PluginLifecycle::actorFromEmail($this->option('actor')));
        } catch (PluginLifecycleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$key} purged. Tables: ".(implode(', ', $summary['dropped_tables']) ?: 'none (rolled back)').'.');

        return self::SUCCESS;
    }
}
