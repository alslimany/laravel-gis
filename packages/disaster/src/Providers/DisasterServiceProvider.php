<?php

namespace Lumina\Disaster\Providers;

use App\Events\WarningIssued;
use App\Models\User;
use App\Plugins\PluginServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Lumina\Disaster\Console\ComputeProximityCommand;
use Lumina\Disaster\Console\EscalateWarningsCommand;
use Lumina\Disaster\Console\ExpireWorkspaceMembersCommand;
use Lumina\Disaster\Console\ExposureReportCommand;
use Lumina\Disaster\Console\GeofenceCheckCommand;
use Lumina\Disaster\Console\PruneAvlCommand;
use Lumina\Disaster\Console\PruneRegistryCommand;
use Lumina\Disaster\Console\PublishAssetLayersCommand;
use Lumina\Disaster\Console\RecomputeEvacRoutesCommand;
use Lumina\Disaster\Console\RetryDisseminationCommand;
use Lumina\Disaster\Console\SeedDemoCommand;
use Lumina\Disaster\Console\SeedNcmcdDemoCommand;
use Lumina\Disaster\Contracts\RoutingEngine;
use Lumina\Disaster\Contracts\SmsGateway;
use Lumina\Disaster\Http\Controllers\PublicPortalController;
use Lumina\Disaster\Jobs\PruneAvlJob;
use Lumina\Disaster\Listeners\CreateActivationFromWarning;
use Lumina\Disaster\Services\CapDisseminationService;
use Lumina\Disaster\Services\Routing\NullRoutingEngine;
use Lumina\Disaster\Services\Routing\PgRoutingEngine;
use Lumina\Disaster\Support\Cap\Cap12Builder;
use Lumina\Disaster\Support\Cap\Cap12Validator;
use Lumina\Disaster\Support\Dissemination\CapChannelAdapter;
use Lumina\Disaster\Support\Dissemination\RadioChannelAdapter;
use Lumina\Disaster\Support\Dissemination\SmsChannelAdapter;
use Lumina\Disaster\Support\Sms\LogSmsGateway;
use Lumina\Disaster\Support\Sms\NullSmsGateway;

/**
 * Disaster & EM plugin provider. Migrations run via plugin:install|upgrade only (D15).
 */
class DisasterServiceProvider extends PluginServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/disaster.php', 'disaster');

        $this->app->singleton(RoutingEngine::class, function () {
            $choice = (string) config('disaster.routing.engine', 'auto');

            if ($choice === 'null') {
                return new NullRoutingEngine;
            }

            if ($choice === 'pgrouting') {
                return new PgRoutingEngine;
            }

            // auto: prefer pgRouting on pgsql; NullRoutingEngine for sqlite / tests.
            if (DB::connection()->getDriverName() === 'pgsql') {
                return new PgRoutingEngine;
            }

            return new NullRoutingEngine;
        });

        $this->app->singleton(SmsGateway::class, function () {
            return match ((string) config('disaster.sms.driver', 'log')) {
                'null' => new NullSmsGateway,
                default => new LogSmsGateway,
            };
        });

        $this->app->singleton(CapDisseminationService::class, function ($app) {
            $gateway = $app->make(SmsGateway::class);

            return new CapDisseminationService(
                $app->make(Cap12Builder::class),
                $app->make(Cap12Validator::class),
                [
                    $app->make(CapChannelAdapter::class),
                    new SmsChannelAdapter($gateway),
                    $app->make(RadioChannelAdapter::class),
                ],
            );
        });
    }

    public function key(): string
    {
        return 'disaster';
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
            ComputeProximityCommand::class,
            ExposureReportCommand::class,
            EscalateWarningsCommand::class,
            ExpireWorkspaceMembersCommand::class,
            GeofenceCheckCommand::class,
            PruneAvlCommand::class,
            PruneRegistryCommand::class,
            PublishAssetLayersCommand::class,
            RecomputeEvacRoutesCommand::class,
            RetryDisseminationCommand::class,
            SeedDemoCommand::class,
            SeedNcmcdDemoCommand::class,
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->job(new PruneAvlJob)
            ->dailyAt('03:15')
            ->withoutOverlapping(120)
            ->name('disaster:prune-avl');

        $schedule->command('disaster:geofence-check')
            ->hourly()
            ->withoutOverlapping(30)
            ->name('disaster:geofence-check');

        $schedule->command('disaster:escalate-warnings')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->name('disaster:escalate-warnings');

        $schedule->command('disaster:expire-workspace-members')
            ->hourly()
            ->withoutOverlapping(30)
            ->name('disaster:expire-workspace-members');

        $schedule->command('disaster:retry-dissemination')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->name('disaster:retry-dissemination');

        $schedule->command('disaster:prune-registry')
            ->dailyAt('04:00')
            ->withoutOverlapping(120)
            ->name('disaster:prune-registry');
    }

    protected function bootPlugin(): void
    {
        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'disaster');

        Event::listen(WarningIssued::class, CreateActivationFromWarning::class);

        // Org admin only — separate from disaster.manage; overrides default member gate.
        Gate::define('disaster.approve_evacuation', function (User $user): bool {
            if ($user->organization_id === null) {
                return false;
            }

            return $user->hasRole('admin');
        });

        // ICS command staff: roles assignment + IAP approval (org admin; same pattern as approve_evacuation).
        Gate::define('disaster.command', function (User $user): bool {
            if ($user->organization_id === null) {
                return false;
            }

            return $user->hasRole('admin');
        });

        // Registry (shelters/beneficiaries/missing persons): org user who is admin OR has disaster.manage.
        // Field-level ACL can tighten later; do not broaden to all members without legal review.
        Gate::define('disaster.registry', function (User $user): bool {
            if ($user->organization_id === null) {
                return false;
            }

            return $user->hasRole('admin') || Gate::forUser($user)->allows('disaster.manage');
        });

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'throttle:60,1'])
            ->prefix('dm/public')
            ->name('disaster.public.')
            ->group(function () {
                Route::get('{token}', [PublicPortalController::class, 'show'])->name('show');
                Route::get('{token}/feed', [PublicPortalController::class, 'feed'])->name('feed');
                Route::get('{token}/shelter-occupancy', [PublicPortalController::class, 'shelterOccupancy'])
                    ->name('shelter-occupancy');
            });

        // Partner EDXL exchange — Bearer tokens (dm_exchange_tokens), not session auth.
        Route::prefix('x/disaster/api/v1/exchange')
            ->name('disaster.exchange.')
            ->group(dirname(__DIR__, 2).'/routes/exchange.php');
    }
}
