<?php

namespace Lumina\DesktopConnect\Providers;

use App\Plugins\PluginServiceProvider;
use Illuminate\Support\Facades\Gate;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Policies\GrantPolicy;
use Lumina\DesktopConnect\Services\DesktopAccess;
use Lumina\DesktopConnect\Services\GrantManager;

/**
 * Desktop GIS Connect plugin provider (ADR-0002).
 * Migrations run via plugin:install|upgrade only (ADR-0001 D15).
 */
class DesktopConnectServiceProvider extends PluginServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/desktop-connect.php', 'desktop-connect');
        $this->app->singleton(DesktopAccess::class);
        $this->app->singleton(GrantManager::class);
    }

    public function key(): string
    {
        return 'desktop-connect';
    }

    public static function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    public function routes(): ?string
    {
        return dirname(__DIR__, 2).'/routes/web.php';
    }

    protected function bootPlugin(): void
    {
        Gate::policy(Grant::class, GrantPolicy::class);
    }
}
