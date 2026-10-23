<?php

namespace Lumina\EarlyWarning\Providers;

use App\Plugins\PluginServiceProvider;
use Lumina\EarlyWarning\Console\LinkBasinsCommand;

/**
 * Early Warning plugin provider. Migrations are deliberately NOT loaded into
 * `artisan migrate`; plugin install runs them from migrationsPath() (D15).
 */
class EarlyWarningServiceProvider extends PluginServiceProvider
{
    public function key(): string
    {
        return 'early-warning';
    }

    public static function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    public function consoleCommands(): array
    {
        return [LinkBasinsCommand::class];
    }
}
