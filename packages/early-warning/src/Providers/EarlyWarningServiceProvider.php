<?php

namespace Lumina\EarlyWarning\Providers;

use Illuminate\Support\ServiceProvider;
use Lumina\EarlyWarning\Console\LinkBasinsCommand;

/**
 * TODO(#54): extend Lumina\Plugins\PluginServiceProvider once the core plugin
 * registry lands. Migrations are deliberately NOT loaded into `artisan migrate`;
 * plugin:install runs them from migrationsPath().
 */
class EarlyWarningServiceProvider extends ServiceProvider
{
    public static function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([LinkBasinsCommand::class]);
        }
    }
}
