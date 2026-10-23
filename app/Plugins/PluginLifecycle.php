<?php

namespace App\Plugins;

use App\Models\InstalledPlugin;
use App\Models\Layer;
use App\Models\PluginEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Plugin install / upgrade / uninstall / purge (ADR-0001 D4, D15, §6, §8).
 *
 * CLI or queued job only, never in a web request. Plugin migrations run with
 * `migrate --path=<plugin> --realpath --force` in their own batch after core;
 * every batch is appended to installed_plugins.meta.batches and rolled back
 * newest-first with `migrate:rollback --batch=<n>` (never --step).
 */
class PluginLifecycle
{
    public function __construct(
        protected PluginManager $plugins,
        protected PluginBackup $backup,
    ) {}

    public function install(string $key, ?User $actor = null): InstalledPlugin
    {
        $manifest = $this->manifestOrFail($key);
        $this->assertCoreMigrated();

        $row = InstalledPlugin::query()->where('plugin_key', $key)->first() ?? new InstalledPlugin;
        if ($row->exists && $row->status === InstalledPlugin::STATUS_INSTALLED) {
            throw new PluginLifecycleException("{$key} is already installed.");
        }
        if ($row->exists && in_array($row->status, [InstalledPlugin::STATUS_INSTALLING, InstalledPlugin::STATUS_UPGRADING], true)) {
            throw new PluginLifecycleException("{$key} is mid-{$row->status}; fix or roll back first.");
        }

        $fromVersion = $row->exists ? $row->version : null;
        $meta = $row->meta ?? [];
        $this->transition($row, InstalledPlugin::STATUS_INSTALLING, [
            'plugin_key' => $key,
            'version' => $row->version ?? (string) $manifest['version'],
            'installed_by' => $actor?->getKey(),
        ]);

        try {
            $backup = $this->backup->dump($key, $this->prefix($manifest), 'install');
            $batch = $this->migrate($manifest);
            $this->provider($manifest)?->onInstall();
        } catch (Throwable $e) {
            $this->fail($row, PluginEvent::INSTALL, $actor, $fromVersion, (string) $manifest['version'], $e);
            throw $e;
        }

        $meta['batches'] = $this->appendBatch($meta['batches'] ?? [], $batch);
        $meta['migrations'] = $this->pluginMigrations($manifest);
        $this->transition($row, InstalledPlugin::STATUS_INSTALLED, [
            'version' => (string) $manifest['version'],
            'installed_at' => now(),
            'uninstalled_at' => null,
            'meta' => $meta,
        ]);
        $this->clearSourceMissing($key);

        PluginEvent::record($key, PluginEvent::INSTALL, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $fromVersion,
            'to_version' => (string) $manifest['version'],
            'meta' => ['batch' => $batch, 'backup' => $backup],
        ]);

        return $row->fresh();
    }

    public function upgrade(string $key, ?User $actor = null): InstalledPlugin
    {
        $manifest = $this->manifestOrFail($key);
        $this->assertCoreMigrated();
        $row = $this->installedOrFail($key, [InstalledPlugin::STATUS_INSTALLED, InstalledPlugin::STATUS_FAILED]);

        $from = (string) $row->version;
        $to = (string) $manifest['version'];
        if (version_compare($to, $from, '<=') && $row->status === InstalledPlugin::STATUS_INSTALLED) {
            throw new PluginLifecycleException("{$key} {$from} is current (code is {$to}).");
        }

        $this->transition($row, InstalledPlugin::STATUS_UPGRADING);

        try {
            $backup = $this->backup->dump($key, $this->prefix($manifest), 'upgrade');
            $batch = $this->migrate($manifest);
        } catch (Throwable $e) {
            $this->fail($row, PluginEvent::UPGRADE, $actor, $from, $to, $e);
            throw $e;
        }

        $meta = $row->meta ?? [];
        $meta['batches'] = $this->appendBatch($meta['batches'] ?? [], $batch);
        $meta['migrations'] = $this->pluginMigrations($manifest);
        $this->transition($row, InstalledPlugin::STATUS_INSTALLED, ['version' => $to, 'meta' => $meta]);

        PluginEvent::record($key, PluginEvent::UPGRADE, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $from,
            'to_version' => $to,
            'meta' => ['batch' => $batch, 'backup' => $backup],
        ]);

        return $row->fresh();
    }

    /**
     * Flip state only: routes 404, jobs off, data kept indefinitely.
     */
    public function uninstall(string $key, ?User $actor = null): InstalledPlugin
    {
        $manifest = $this->plugins->manifest($key);
        $row = $this->installedOrFail($key, [InstalledPlugin::STATUS_INSTALLED, InstalledPlugin::STATUS_FAILED]);

        if ($manifest) {
            $this->provider($manifest)?->onUninstall(false);
        }
        $this->transition($row, InstalledPlugin::STATUS_UNINSTALLED, ['uninstalled_at' => now()]);
        $this->markSourceMissing($key);

        PluginEvent::record($key, PluginEvent::UNINSTALL, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $row->version,
        ]);

        return $row->fresh();
    }

    /**
     * Requires uninstalled. Dumps plugin tables, rolls back the plugin's own
     * batches newest-first, drops any leftover plugin-prefixed tables and
     * deletes layers created by the plugin. Never touches core tables.
     *
     * @return array<string, mixed> summary
     */
    public function purge(string $key, ?User $actor = null): array
    {
        $manifest = $this->manifestOrFail($key);
        $row = $this->installedOrFail($key, [InstalledPlugin::STATUS_UNINSTALLED]);
        $prefix = $this->prefix($manifest);

        try {
            $backup = $this->backup->dump($key, $prefix, 'purge');

            $batches = $row->meta['batches'] ?? [];
            rsort($batches);
            foreach ($batches as $batch) {
                $this->rollbackBatch($manifest, (int) $batch);
            }

            $dropped = [];
            foreach ($this->pluginTables($manifest) as $table) {
                if (Schema::hasTable($table)) {
                    Schema::drop($table);
                    $dropped[] = $table;
                }
            }

            $layers = $this->pluginLayers($key);
            $layerIds = $layers->pluck('id')->all();
            $layers->each->delete();

            $this->provider($manifest)?->onUninstall(true);
        } catch (Throwable $e) {
            PluginEvent::record($key, PluginEvent::PURGE, [
                'actor_id' => $actor?->getKey(),
                'from_version' => $row->version,
                'outcome' => 'failed',
                'meta' => ['error' => $e->getMessage()],
            ]);
            throw $e;
        }

        $summary = [
            'batches' => $batches,
            'dropped_tables' => $dropped,
            'deleted_layers' => $layerIds,
            'backup' => $backup,
        ];

        PluginEvent::record($key, PluginEvent::PURGE, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $row->version,
            'meta' => $summary,
        ]);
        $row->delete();

        return $summary;
    }

    /**
     * Layers created by a plugin (metadata.plugin = key).
     *
     * @return Collection<int, Layer>
     */
    public function pluginLayers(string $key)
    {
        return Layer::query()->whereNotNull('metadata')->get()
            ->filter(fn (Layer $layer) => ($layer->metadata['plugin'] ?? null) === $key)
            ->values();
    }

    /**
     * Table prefix owned by a plugin, from its manifest data.tables.
     */
    public function prefix(array $manifest): string
    {
        if (! empty($manifest['data']['prefix'])) {
            return (string) $manifest['data']['prefix'];
        }

        $first = $this->pluginTables($manifest)[0] ?? null;

        return $first !== null && str_contains($first, '_') ? strstr($first, '_', true).'_' : '';
    }

    /**
     * @return list<string>
     */
    public function pluginTables(array $manifest): array
    {
        return array_values(array_unique(array_merge(
            $manifest['data']['tables'] ?? [],
            $manifest['data']['global_tables'] ?? [],
        )));
    }

    protected function migrate(array $manifest): ?int
    {
        $path = $this->migrationsPath($manifest);
        if ($path === null) {
            return null;
        }

        $before = (int) DB::table('migrations')->max('batch');
        $exit = Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);
        if ($exit !== 0) {
            throw new PluginLifecycleException('Plugin migrate failed: '.trim(Artisan::output()));
        }
        $after = (int) DB::table('migrations')->max('batch');

        return $after > $before ? $after : null;
    }

    protected function rollbackBatch(array $manifest, int $batch): void
    {
        $path = $this->migrationsPath($manifest);
        $pluginMigrations = $this->pluginMigrations($manifest);
        $inBatch = DB::table('migrations')->where('batch', $batch)->pluck('migration')->all();

        // Never roll back a batch that holds anything other than this plugin.
        if (array_diff($inBatch, $pluginMigrations) !== []) {
            throw new PluginLifecycleException("Batch {$batch} contains non-plugin migrations; refusing to roll back.");
        }

        $exit = Artisan::call('migrate:rollback', [
            '--batch' => $batch, '--path' => $path, '--realpath' => true, '--force' => true,
        ]);
        if ($exit !== 0) {
            throw new PluginLifecycleException("Rollback of batch {$batch} failed: ".trim(Artisan::output()));
        }
    }

    /**
     * Migration names (file basenames) shipped by the plugin.
     *
     * @return list<string>
     */
    protected function pluginMigrations(array $manifest): array
    {
        $path = $this->migrationsPath($manifest);
        if ($path === null || ! is_dir($path)) {
            return [];
        }

        return collect(glob($path.'/*.php'))->map(fn ($file) => basename($file, '.php'))->sort()->values()->all();
    }

    protected function migrationsPath(array $manifest): ?string
    {
        $class = $manifest['provider'] ?? null;
        if (! $class || ! class_exists($class) || ! is_subclass_of($class, PluginServiceProvider::class)) {
            return null;
        }

        return $class::migrationsPath();
    }

    protected function provider(array $manifest): ?PluginServiceProvider
    {
        $class = $manifest['provider'] ?? null;
        if (! $class || ! class_exists($class) || ! is_subclass_of($class, PluginServiceProvider::class)) {
            return null;
        }

        return app()->getProvider($class) ?? new $class(app());
    }

    protected function assertCoreMigrated(): void
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $ran = $migrator->getRepository()->getRan();

        if (array_diff($files, $ran) !== []) {
            throw new PluginLifecycleException('Run core `php artisan migrate` before installing or upgrading plugins.');
        }
    }

    protected function manifestOrFail(string $key): array
    {
        $manifest = $this->plugins->manifest($key);
        if ($manifest === null) {
            throw new PluginLifecycleException("Unknown plugin {$key} (not a composer dependency).");
        }
        if (empty($manifest['version'])) {
            throw new PluginLifecycleException("Plugin {$key} manifest has no version.");
        }
        $class = $manifest['provider'] ?? null;
        if ($class && class_exists($class) && is_subclass_of($class, PluginServiceProvider::class)) {
            $provider = app()->getProvider($class) ?? new $class(app());
            if ($provider->key() !== $key) {
                throw new PluginLifecycleException("Provider key {$provider->key()} does not match manifest key {$key}.");
            }
        }

        return $manifest;
    }

    /**
     * @param  list<string>  $statuses
     */
    protected function installedOrFail(string $key, array $statuses): InstalledPlugin
    {
        $row = InstalledPlugin::query()->where('plugin_key', $key)->first();
        if (! $row || ! in_array($row->status, $statuses, true)) {
            $state = $row?->status ?? 'not installed';
            throw new PluginLifecycleException("{$key} is {$state}; expected ".implode(' or ', $statuses).'.');
        }

        return $row;
    }

    protected function transition(InstalledPlugin $row, string $status, array $attributes = []): void
    {
        $row->forceFill(array_merge($attributes, ['status' => $status]))->save();
    }

    protected function fail(InstalledPlugin $row, string $action, ?User $actor, ?string $from, ?string $to, Throwable $e): void
    {
        $this->transition($row, InstalledPlugin::STATUS_FAILED);
        PluginEvent::record($row->plugin_key, $action, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $from,
            'to_version' => $to,
            'outcome' => 'failed',
            'meta' => ['error' => $e->getMessage()],
        ]);
    }

    /**
     * @param  list<int>  $batches
     * @return list<int>
     */
    protected function appendBatch(array $batches, ?int $batch): array
    {
        if ($batch !== null) {
            $batches[] = $batch;
        }

        return array_values(array_unique(array_map('intval', $batches)));
    }

    protected function markSourceMissing(string $key): void
    {
        foreach ($this->pluginLayers($key) as $layer) {
            $layer->forceFill(['metadata' => array_merge($layer->metadata ?? [], ['source_missing' => true])])->save();
        }
    }

    protected function clearSourceMissing(string $key): void
    {
        foreach ($this->pluginLayers($key) as $layer) {
            $metadata = $layer->metadata ?? [];
            if (array_key_exists('source_missing', $metadata)) {
                unset($metadata['source_missing']);
                $layer->forceFill(['metadata' => $metadata])->save();
            }
        }
    }

    public static function actorFromEmail(?string $email): ?User
    {
        if ($email === null || $email === '') {
            return null;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user || ! $user->isSuperAdmin()) {
            throw new PluginLifecycleException('--actor must be the email of a super admin.');
        }

        return $user;
    }
}
