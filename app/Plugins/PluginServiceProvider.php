<?php

namespace App\Plugins;

use App\Models\Organization;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class every first-party plugin provider extends (ADR-0001 §4).
 * Plugin packages live under Lumina\<Key>; this base stays in core (App\).
 *
 * Routes are always registered (route:cache safe) behind
 * web, auth, organization and plugin:{key}; gating happens per request.
 * Migrations are never auto-loaded into `artisan migrate`; they run only
 * through the plugin install/upgrade path in their own batch (D15).
 *
 * Extension points: boot() is final; put boot work in bootPlugin() and
 * commands in consoleCommands(). register() may be overridden but MUST call
 * parent::register(); boot() throws a LogicException otherwise.
 */
abstract class PluginServiceProvider extends ServiceProvider
{
    /**
     * Must equal the manifest key in extra.lumina.
     */
    abstract public function key(): string;

    private bool $pluginRegistered = false;

    /**
     * Subclasses overriding this must call parent::register().
     */
    public function register(): void
    {
        $this->pluginRegistered = true;
    }

    /**
     * Absolute path to the plugin routes file, or null.
     */
    public function routes(): ?string
    {
        return null;
    }

    /**
     * Absolute path to the plugin migrations. Static so install tooling and
     * tests can resolve it without booting the provider.
     */
    public static function migrationsPath(): ?string
    {
        return null;
    }

    /**
     * Artisan commands the plugin registers (console only).
     *
     * @return list<class-string>
     */
    public function consoleCommands(): array
    {
        return [];
    }

    /**
     * The only place scheduled tasks are declared (guarded by install state).
     */
    public function schedule(Schedule $schedule): void {}

    public function onInstall(): void {}

    public function onEnable(Organization $organization): void {}

    public function onDisable(Organization $organization): void {}

    public function onUninstall(bool $purge): void {}

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return [];
    }

    /**
     * Extra plugin boot work (config, policies). Do not load migrations here.
     */
    protected function bootPlugin(): void {}

    final public function boot(): void
    {
        if (! $this->pluginRegistered) {
            throw new \LogicException(static::class.'::register() must call parent::register().');
        }

        $key = $this->key();

        if (($routes = $this->routes()) !== null && ! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'organization', "plugin:{$key}"])
                ->prefix("x/{$key}")
                ->name("{$key}.")
                ->group($routes);
        }

        if ($this->app->runningInConsole() && $this->consoleCommands() !== []) {
            $this->commands($this->consoleCommands());
        }

        $this->bootPlugin();
    }
}
