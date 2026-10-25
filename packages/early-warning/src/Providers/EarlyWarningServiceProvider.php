<?php

namespace Lumina\EarlyWarning\Providers;

use App\Events\LayerDeleted;
use App\Events\LayerUpdated;
use App\Models\Organization;
use App\Plugins\PluginManager;
use App\Plugins\PluginServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Lumina\EarlyWarning\Alerts\DefaultAlertRules;
use Lumina\EarlyWarning\Console\ComputeExposureCommand;
use Lumina\EarlyWarning\Console\ComputeRiskCommand;
use Lumina\EarlyWarning\Console\DispatchAlertsCommand;
use Lumina\EarlyWarning\Console\EvaluateAlertsCommand;
use Lumina\EarlyWarning\Console\FetchForecastsCommand;
use Lumina\EarlyWarning\Console\FetchObservationsCommand;
use Lumina\EarlyWarning\Console\LinkBasinsCommand;
use Lumina\EarlyWarning\Console\PublishRiskLayerCommand;
use Lumina\EarlyWarning\Console\SeedAlertRulesCommand;
use Lumina\EarlyWarning\Console\SuggestThresholdsCommand;
use Lumina\EarlyWarning\Http\Controllers\PublicFeedController;
use Lumina\EarlyWarning\Jobs\ComputeExposureJob;
use Lumina\EarlyWarning\Jobs\ComputeRiskJob;
use Lumina\EarlyWarning\Jobs\DispatchAlertsJob;
use Lumina\EarlyWarning\Jobs\EvaluateAlertsJob;
use Lumina\EarlyWarning\Jobs\FetchForecastsJob;
use Lumina\EarlyWarning\Jobs\FetchObservationsJob;
use Lumina\EarlyWarning\Listeners\RecomputeWhenCoreLayerChanges;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Subscription;

/**
 * Early Warning plugin provider. Migrations are deliberately NOT loaded into
 * `artisan migrate`; plugin install/upgrade runs them from migrationsPath() (D15).
 */
class EarlyWarningServiceProvider extends PluginServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/early-warning.php', 'early-warning');
    }

    public function key(): string
    {
        return 'early-warning';
    }

    public static function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    public function routes(): ?string
    {
        return dirname(__DIR__, 2).'/routes/web.php';
    }

    public function consoleCommands(): array
    {
        return [
            LinkBasinsCommand::class,
            FetchForecastsCommand::class,
            FetchObservationsCommand::class,
            ComputeExposureCommand::class,
            ComputeRiskCommand::class,
            PublishRiskLayerCommand::class,
            EvaluateAlertsCommand::class,
            DispatchAlertsCommand::class,
            SeedAlertRulesCommand::class,
            SuggestThresholdsCommand::class,
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        // Global jobs: only when installed (PluginManager::registerSchedules gates that).
        $schedule->job(new FetchForecastsJob)
            ->hourly()
            ->withoutOverlapping(55)
            ->name('early-warning:fetch-forecasts');

        $schedule->job(new FetchObservationsJob)
            ->everySixHours()
            ->withoutOverlapping(120)
            ->name('early-warning:fetch-observations');

        $schedule->job(new ComputeRiskJob)
            ->hourlyAt(5)
            ->withoutOverlapping(55)
            ->name('early-warning:compute-risk');

        $schedule->job(new EvaluateAlertsJob)
            ->hourlyAt(10)
            ->withoutOverlapping(55)
            ->name('early-warning:evaluate-alerts');

        $schedule->job(new DispatchAlertsJob)
            ->hourlyAt(15)
            ->withoutOverlapping(55)
            ->name('early-warning:dispatch-alerts');

        $schedule->job(new ComputeExposureJob)
            ->dailyAt('02:30')
            ->withoutOverlapping(180)
            ->name('early-warning:compute-exposure');
    }

    public function onEnable(Organization $organization): void
    {
        // PluginRegistry tests (and enable-before-migrate edge cases) call enable
        // without EW tables present. Seed only when the install schema is ready.
        if (! Schema::hasTable('ew_basins') || ! Schema::hasTable('ew_subscriptions')) {
            return;
        }

        $now = now();
        foreach (Basin::query()->pluck('basin_code') as $code) {
            Subscription::query()->firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'basin_code' => $code,
                ],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        if (Schema::hasTable('ew_alert_rules')) {
            app(DefaultAlertRules::class)->seed($organization);
        }
    }

    public function healthCheck(): array
    {
        return [
            'installed' => app(PluginManager::class)->isInstalled($this->key()),
            'config' => [
                'open_meteo' => config('early-warning.open_meteo.base_url'),
                'forecast_days' => config('early-warning.open_meteo.forecast_days'),
                'risk_horizon_hours' => config('early-warning.risk.horizon_hours'),
                'observations_enabled' => config('early-warning.observations.enabled'),
                'observations_source' => config('early-warning.observations.source'),
            ],
        ];
    }

    /**
     * Public token endpoints live outside the auth+plugin group (ADR §10).
     * Installed/enabled gating runs inside PublicFeedController.
     */
    protected function bootPlugin(): void
    {
        Event::listen(LayerUpdated::class, RecomputeWhenCoreLayerChanges::class);
        Event::listen(LayerDeleted::class, RecomputeWhenCoreLayerChanges::class);

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'throttle:60,1'])
            ->prefix('ew/public')
            ->name('early-warning.public.')
            ->group(function () {
                Route::get('{token}', [PublicFeedController::class, 'show'])->name('show');
                Route::get('{token}/feed', [PublicFeedController::class, 'feed'])->name('feed');
                Route::post('{token}/reports', [PublicFeedController::class, 'storeReport'])
                    ->middleware('throttle:20,1')
                    ->name('reports');
            });
    }
}
