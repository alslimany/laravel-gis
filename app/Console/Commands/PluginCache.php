<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class PluginCache extends Command
{
    protected $signature = 'plugin:cache';

    protected $description = 'Warm the plugin registry cache (run on deploy next to route:cache)';

    public function handle(PluginManager $plugins): int
    {
        $plugins->forgetRegistry();
        $count = count($plugins->registry());
        $this->info("Plugin registry cached ({$count} plugins).");

        return self::SUCCESS;
    }
}
