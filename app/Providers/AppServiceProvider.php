<?php

namespace App\Providers;

use App\Services\GeoServerService;
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
    }
}
