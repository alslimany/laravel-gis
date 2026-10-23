<?php

namespace App\Plugins;

use App\Models\InstalledPlugin;
use App\Models\Layer;
use App\Models\PluginEvent;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
 *
 * Batches are reconciled from the migrations table (rows whose migration is
 * one of the plugin's files) in a finally block, so a failed install/upgrade
 * still records what ran and `plugin:rollback` can undo it.
 *
 * Every operation holds one global lifecycle lock on the cache store named by
 * config('plugins.lock_store'); that store must be shared by every app/worker
 * node (database or redis, never array/file in multi-node deployments).
 */
class PluginLifecycle
{
    public function __construct(
        protected PluginManager $plugins,
        protected PluginBackup $backup,
    ) {}

    public function install(string $key, ?User $actor = null, bool $force = false): InstalledPlugin
    {
        return $this->locked(fn () => $this->doInstall($key, $actor, $force));
    }

    protected function doInstall(string $key, ?User $actor, bool $force = false): InstalledPlugin
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

        $this->assertNoPending($row, $force);

        $fromVersion = $row->exists ? $row->version : null;
        $meta = $row->meta ?? [];
        // --force keeps the original pending record so plugin:rollback still targets the first failure.
        $meta['pending'] ??= [
            'action' => PluginEvent::INSTALL,
            'prior_batches' => $this->recordedBatches($manifest),
            'prior_status' => $row->exists ? $row->status : null,
            'prior_version' => $fromVersion,
            'backup' => null,
        ];
        $keepBackup = $meta['pending']['backup'] !== null;
        $this->transition($row, InstalledPlugin::STATUS_INSTALLING, [
            'plugin_key' => $key,
            'version' => $row->version ?? (string) $manifest['version'],
            'installed_by' => $actor?->getKey(),
            'meta' => $meta,
        ]);

        $batch = null;
        try {
            $backup = $this->backup->dump($key, $this->prefix($manifest), 'install');
            if (! $keepBackup) {
                $meta['pending']['backup'] = $backup['file'];
            }
            $batch = $this->migrate($manifest);
            $this->provider($manifest)?->onInstall();
        } catch (Throwable $e) {
            $this->fail($row, PluginEvent::INSTALL, $actor, $fromVersion, (string) $manifest['version'], $e);
            throw $e;
        } finally {
            $meta['batches'] = $this->recordedBatches($manifest);
            $meta['migrations'] = $this->pluginMigrations($manifest);
            $row->forceFill(['meta' => $meta])->save();
        }

        unset($meta['pending']);
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
            'meta' => ['batch' => $batch, 'backup' => $backup['file'], 'backup_skipped' => $backup['skipped']],
        ]);

        return $row->fresh();
    }

    public function upgrade(string $key, ?User $actor = null, bool $force = false): InstalledPlugin
    {
        return $this->locked(fn () => $this->doUpgrade($key, $actor, $force));
    }

    protected function doUpgrade(string $key, ?User $actor, bool $force = false): InstalledPlugin
    {
        $manifest = $this->manifestOrFail($key);
        $this->assertCoreMigrated();
        $row = $this->installedOrFail($key, [InstalledPlugin::STATUS_INSTALLED, InstalledPlugin::STATUS_FAILED]);

        $from = (string) $row->version;
        $to = (string) $manifest['version'];
        if (version_compare($to, $from, '<=') && $row->status === InstalledPlugin::STATUS_INSTALLED) {
            throw new PluginLifecycleException("{$key} {$from} is current (code is {$to}).");
        }

        $this->assertNoPending($row, $force);

        $meta = $row->meta ?? [];
        $meta['pending'] ??= [
            'action' => PluginEvent::UPGRADE,
            'prior_batches' => $this->recordedBatches($manifest),
            'prior_status' => InstalledPlugin::STATUS_INSTALLED,
            'prior_version' => $from,
            'backup' => null,
        ];
        $keepBackup = $meta['pending']['backup'] !== null;
        $this->transition($row, InstalledPlugin::STATUS_UPGRADING, ['meta' => $meta]);

        $batch = null;
        try {
            $backup = $this->backup->dump($key, $this->prefix($manifest), 'upgrade');
            if (! $keepBackup) {
                $meta['pending']['backup'] = $backup['file'];
            }
            $batch = $this->migrate($manifest);
        } catch (Throwable $e) {
            $this->fail($row, PluginEvent::UPGRADE, $actor, $from, $to, $e);
            throw $e;
        } finally {
            $meta['batches'] = $this->recordedBatches($manifest);
            $meta['migrations'] = $this->pluginMigrations($manifest);
            $row->forceFill(['meta' => $meta])->save();
        }

        unset($meta['pending']);
        $this->transition($row, InstalledPlugin::STATUS_INSTALLED, ['version' => $to, 'meta' => $meta]);

        PluginEvent::record($key, PluginEvent::UPGRADE, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $from,
            'to_version' => $to,
            'meta' => ['batch' => $batch, 'backup' => $backup['file'], 'backup_skipped' => $backup['skipped']],
        ]);

        return $row->fresh();
    }

    /**
     * Flip state only: routes 404, jobs off, data kept indefinitely.
     */
    public function uninstall(string $key, ?User $actor = null): InstalledPlugin
    {
        return $this->locked(fn () => $this->doUninstall($key, $actor));
    }

    protected function doUninstall(string $key, ?User $actor): InstalledPlugin
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
     * Requires uninstalled. Dumps plugin tables, then (in one DB transaction
     * where the driver allows DDL in transactions) deletes the plugin's layers,
     * rolls back its own batches newest-first and drops leftover manifest
     * tables. Never touches core tables.
     *
     * TODO(follow-up ticket): unpublish the plugin's GeoServer layers/styles;
     * purge does not touch GeoServer yet (the command warns about this).
     *
     * @return array<string, mixed> summary
     */
    public function purge(string $key, ?User $actor = null): array
    {
        return $this->locked(fn () => $this->doPurge($key, $actor));
    }

    protected function doPurge(string $key, ?User $actor): array
    {
        $manifest = $this->manifestOrFail($key);
        $row = $this->installedOrFail($key, [InstalledPlugin::STATUS_UNINSTALLED]);
        $prefix = $this->prefix($manifest);

        // Check every batch before backing up or deleting anything.
        $batches = $this->batchesFor($row, $manifest);
        rsort($batches);
        foreach ($batches as $batch) {
            $this->assertPluginOnlyBatch($manifest, (int) $batch);
        }

        try {
            $backup = $this->backup->dump($key, $prefix, 'purge');

            [$layerIds, $dropped] = DB::transaction(function () use ($key, $manifest, $batches) {
                $layers = $this->pluginLayers($key);
                $layerIds = $layers->pluck('id')->all();
                $layers->each->delete();

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

                return [$layerIds, $dropped];
            });

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
            'backup' => $backup['file'],
            'backup_skipped' => $backup['skipped'],
            'geoserver' => 'not unpublished (follow-up ticket)',
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
     * Undo a failed install/upgrade: roll back every batch recorded since the
     * operation started (newest first), check no plugin migration rows are
     * orphaned, restore the pre-operation backup (pgsql) and return the row
     * to its prior state.
     *
     * @return array<string, mixed> summary
     */
    public function rollback(string $key, ?User $actor = null, bool $pruneMigrationRows = false): array
    {
        return $this->locked(fn () => $this->doRollback($key, $actor, $pruneMigrationRows));
    }

    protected function doRollback(string $key, ?User $actor, bool $pruneMigrationRows = false): array
    {
        $manifest = $this->manifestOrFail($key);
        $row = $this->installedOrFail($key, [
            InstalledPlugin::STATUS_FAILED, InstalledPlugin::STATUS_INSTALLING, InstalledPlugin::STATUS_UPGRADING,
        ]);
        $meta = $row->meta ?? [];
        $pending = $meta['pending'] ?? null;
        if (! is_array($pending)) {
            throw new PluginLifecycleException("{$key} has no recorded failed operation to roll back.");
        }

        $prior = array_map('intval', $pending['prior_batches'] ?? []);
        $batches = array_values(array_diff($this->batchesFor($row, $manifest), $prior));
        rsort($batches);
        foreach ($batches as $batch) {
            $this->assertPluginOnlyBatch($manifest, (int) $batch);
        }

        $backupFile = $pending['backup'] ?? null;
        $failedBatches = [];
        $restoredAfterFailure = false;

        try {
            foreach ($batches as $i => $batch) {
                try {
                    $this->rollbackBatch($manifest, (int) $batch);
                } catch (Throwable $e) {
                    $firstInstall = ($pending['prior_status'] ?? null) === null;
                    if ($backupFile === null && ! $firstInstall) {
                        throw $e;
                    }
                    // A down() failed: drop the plugin tables the dump doesn't
                    // contain and restore the pre-operation dump in one
                    // transaction (first install without a dump: drop all
                    // <prefix>_* tables), then forget the migration rows of the
                    // batches we could not roll back.
                    $failedBatches = array_slice($batches, $i);
                    $this->restoreReplacingPluginTables($manifest, $backupFile);
                    DB::table('migrations')->whereIn('batch', $failedBatches)
                        ->whereIn('migration', $this->pluginMigrations($manifest))->delete();
                    $restoredAfterFailure = true;
                    PluginEvent::record($key, PluginEvent::ROLLBACK, [
                        'actor_id' => $actor?->getKey(),
                        'from_version' => $row->version,
                        'to_version' => $pending['prior_version'] ?? null,
                        'outcome' => 'restored',
                        'meta' => ['error' => $e->getMessage(), 'failed_batches' => $failedBatches, 'backup' => $backupFile],
                    ]);
                    break;
                }
            }

            // Orphan check (reported before any restore): plugin migration rows
            // outside the prior batches.
            $orphanRows = $this->orphanRows($manifest, $prior);
            if ($orphanRows !== [] && ! $pruneMigrationRows) {
                throw new PluginLifecycleException("{$key} has orphan plugin migration rows: "
                    .collect($orphanRows)->map(fn ($r) => "{$r['migration']} (batch {$r['batch']})")->implode(', ')
                    .'. Re-run with --prune-migration-rows to delete them.');
            }
            if ($orphanRows !== []) {
                DB::table('migrations')->whereIn('id', array_column($orphanRows, 'id'))->delete();
            }

            $restored = $restoredAfterFailure || $this->backup->restore($backupFile);

            // First install with no dump: nothing to restore, so remove any
            // <prefix>_* tables it left behind.
            if (! $restoredAfterFailure && $backupFile === null && ($pending['prior_status'] ?? null) === null) {
                $this->dropPluginTables($manifest, []);
            }
        } catch (Throwable $e) {
            $meta['batches'] = $this->recordedBatches($manifest);
            $row->forceFill(['meta' => $meta])->save();
            PluginEvent::record($key, PluginEvent::ROLLBACK, [
                'actor_id' => $actor?->getKey(),
                'from_version' => $row->version,
                'to_version' => $pending['prior_version'] ?? null,
                'outcome' => 'failed',
                'meta' => ['error' => $e->getMessage(), 'action' => $pending['action'] ?? null],
            ]);
            throw $e;
        }

        $meta['batches'] = $this->recordedBatches($manifest);
        unset($meta['pending']);

        $summary = [
            'action' => $pending['action'] ?? null,
            'rolled_back_batches' => $batches,
            'restored_backup' => $restored,
            'failed_batches' => $failedBatches,
            'pruned_orphans' => $orphanRows,
        ];

        PluginEvent::record($key, PluginEvent::ROLLBACK, [
            'actor_id' => $actor?->getKey(),
            'from_version' => $row->version,
            'to_version' => $pending['prior_version'] ?? null,
            'meta' => $summary,
        ]);

        if (($pending['prior_status'] ?? null) === null) {
            $row->delete();
        } else {
            $this->transition($row, $pending['prior_status'], [
                'version' => $pending['prior_version'] ?? $row->version,
                'meta' => $meta,
            ]);
        }

        return $summary;
    }

    /**
     * Layers created by a plugin (metadata.plugin = key).
     *
     * @return Collection<int, Layer>
     */
    public function pluginLayers(string $key)
    {
        return Layer::query()->where('metadata->plugin', $key)->get();
    }

    /**
     * Table prefix owned by a plugin (manifest data.prefix, ADR-0001 rev 4).
     */
    public function prefix(array $manifest): string
    {
        return (string) ($manifest['data']['prefix'] ?? '');
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

    /**
     * Batches that currently hold this plugin's migrations (source of truth).
     *
     * @return list<int>
     */
    public function recordedBatches(array $manifest): array
    {
        $migrations = $this->pluginMigrations($manifest);
        if ($migrations === []) {
            return [];
        }

        return DB::table('migrations')->whereIn('migration', $migrations)
            ->distinct()->orderBy('batch')->pluck('batch')->map(fn ($b) => (int) $b)->values()->all();
    }

    /**
     * @return list<int>
     */
    protected function batchesFor(InstalledPlugin $row, array $manifest): array
    {
        return $this->appendBatches($row->meta['batches'] ?? [], $this->recordedBatches($manifest));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function locked(callable $callback): mixed
    {
        $lock = Cache::store(config('plugins.lock_store'))->lock('plugin-lifecycle', (int) config('plugins.lock_seconds', 14400));

        try {
            return $lock->block((int) config('plugins.lock_wait', 5), $callback);
        } catch (LockTimeoutException) {
            throw new PluginLifecycleException('Another plugin lifecycle operation is running; try again later.');
        }
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

    /**
     * @param  list<int>  $prior
     * @return list<array{id: int, migration: string, batch: int}>
     */
    protected function orphanRows(array $manifest, array $prior): array
    {
        $migrations = $this->pluginMigrations($manifest);
        if ($migrations === []) {
            return [];
        }

        return DB::table('migrations')->whereIn('migration', $migrations)
            ->when($prior !== [], fn ($q) => $q->whereNotIn('batch', $prior))
            ->orderBy('id')->get(['id', 'migration', 'batch'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'migration' => $r->migration, 'batch' => (int) $r->batch])->all();
    }

    /**
     * Drop <prefix>_* tables not in the dump, then replay the dump's SQL, all
     * in one DB transaction (pgsql DDL is transactional, so a core FK conflict
     * makes the whole restore fail atomically).
     */
    protected function restoreReplacingPluginTables(array $manifest, ?string $file): void
    {
        $keep = $file === null ? [] : $this->backup->dumpTables($file);
        $sql = $file === null ? '' : $this->backup->restoreSql($file);

        DB::transaction(function () use ($manifest, $keep, $sql) {
            $this->dropPluginTables($manifest, $keep);
            if (trim($sql) !== '') {
                DB::unprepared($sql);
            }
        });
    }

    /**
     * @param  list<string>  $keep
     * @return list<string> dropped
     */
    protected function dropPluginTables(array $manifest, array $keep): array
    {
        $prefix = $this->prefix($manifest);
        if ($prefix === '') {
            return [];
        }

        $dropped = [];
        $tables = collect(Schema::getTables())
            ->filter(fn ($t) => ! isset($t['schema']) || DB::getDriverName() !== 'pgsql' || $t['schema'] === (DB::getConfig('search_path') ?: 'public'))
            ->pluck('name')->unique()
            ->filter(fn ($name) => str_starts_with((string) $name, $prefix) && ! in_array($name, $keep, true));
        foreach ($tables as $table) {
            Schema::drop($table);
            $dropped[] = $table;
        }

        return $dropped;
    }

    protected function assertNoPending(InstalledPlugin $row, bool $force): void
    {
        if ($row->exists && is_array($row->meta['pending'] ?? null) && ! $force) {
            throw new PluginLifecycleException("{$row->plugin_key} has an unfinished {$row->meta['pending']['action']}; run plugin:rollback first (or --force).");
        }
    }

    protected function assertPluginOnlyBatch(array $manifest, int $batch): void
    {
        $inBatch = DB::table('migrations')->where('batch', $batch)->pluck('migration')->all();

        // Never roll back a batch that holds anything other than this plugin.
        if (array_diff($inBatch, $this->pluginMigrations($manifest)) !== []) {
            throw new PluginLifecycleException("Batch {$batch} contains non-plugin migrations; refusing to roll back.");
        }
    }

    protected function rollbackBatch(array $manifest, int $batch): void
    {
        $path = $this->migrationsPath($manifest);
        $this->assertPluginOnlyBatch($manifest, $batch);

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
        if ($errors = PluginManager::validateManifest($manifest)) {
            throw new PluginLifecycleException("Plugin {$key} manifest is invalid: ".implode(' ', $errors));
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
     * @param  list<int>  $more
     * @return list<int>
     */
    protected function appendBatches(array $batches, array $more): array
    {
        $all = array_values(array_unique(array_map('intval', array_merge($batches, $more))));
        sort($all);

        return $all;
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
