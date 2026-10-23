<?php

namespace App\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\GeoServerService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeoServerService::class, function ($app) {
            return new GeoServerService;
        });

        $this->app->singleton(PluginManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pages live in resources/js/Pages. The package default is js/pages,
        // which does not resolve on case-sensitive filesystems.
        config([
            'inertia.pages.paths' => [resource_path('js/Pages')],
        ]);

        // ADR-0001 §5: install/uninstall is super-admin only; toggling is the
        // org admin for their own org, or a super admin for any org (audited
        // once plugin_events lands).
        Gate::define('plugins.install', fn (User $user) => $user->isSuperAdmin());
        Gate::define('plugins.toggle', function (User $user, Organization $organization) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            return $user->organization_id !== null
                && (int) $user->organization_id === (int) $organization->getKey()
                && $user->hasRole('admin');
        });
    }
}
