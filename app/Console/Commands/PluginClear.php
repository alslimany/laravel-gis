<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class PluginClear extends Command
{
    protected $signature = 'plugin:clear';

    protected $description = 'Clear the plugin registry and gating caches';

    public function handle(PluginManager $plugins): int
    {
        $plugins->forgetRegistry();
        $plugins->forgetInstalled();
        $this->info('Plugin caches cleared.');

        return self::SUCCESS;
    }
}
