<?php

namespace Lumina\DesktopConnect\Providers;

use App\Plugins\PluginServiceProvider;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Lumina\DesktopConnect\Models\ApiKey;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Policies\ApiKeyPolicy;
use Lumina\DesktopConnect\Policies\GrantPolicy;
use Lumina\DesktopConnect\Services\DesktopAccess;
use Lumina\DesktopConnect\Services\DesktopKeyManager;
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
        $this->app->singleton(DesktopKeyManager::class);
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
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);

        if ($this->app->routesAreCached()) {
            return;
        }

        $key = $this->key();
        $apiRoutes = dirname(__DIR__, 2).'/routes/api.php';

        Route::middleware([
            'auth:sanctum',
            'organization',
            "plugin:{$key}",
            SubstituteBindings::class,
        ])
            ->prefix('api/v1/desktop')
            ->name('desktop-connect.api.')
            ->group($apiRoutes);
    }
}
