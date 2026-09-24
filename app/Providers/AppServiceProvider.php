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
        // Package default is resources/js/pages. This app uses Pages, and
        // Linux CI is case-sensitive, so Inertia's page finder must match.
        config([
            'inertia.pages.paths' => [
                resource_path('js/Pages'),
            ],
        ]);
    }
}
