<?php

namespace App\Plugins;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Plugin-only dumps before install/upgrade/purge (ADR-0001 D15, §8).
 *
 * PostgreSQL: `pg_dump -Fc -t '<prefix>*' --dbname=<db>`. When no table with
 * the prefix exists yet (first install) the dump is skipped and the reason is
 * returned for the audit row. When tables exist, a failed or empty dump
 * aborts the operation and the partial file is removed.
 * Other drivers (the SQLite test DB) are skipped with reason "driver".
 */
class PluginBackup
{
    public function __construct(protected ?string $connection = null) {}

    /**
     * @return array{file: ?string, skipped: ?string}
     */
    public function dump(string $key, string $prefix, string $action): array
    {
        $connection = DB::connection($this->connection);
        if ($connection->getDriverName() !== 'pgsql') {
            return ['file' => null, 'skipped' => 'driver'];
        }
        if ($prefix === '') {
            throw new PluginLifecycleException("{$key} has no data.prefix; refusing to run without a backup.");
        }

        $tables = collect($connection->select(
            "select table_name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE'"
        ))->pluck('table_name')->filter(fn ($t) => str_starts_with((string) $t, $prefix));
        if ($tables->isEmpty()) {
            return ['file' => null, 'skipped' => 'no-tables'];
        }

        $dir = storage_path("app/plugin-backups/{$key}");
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $file = $dir.'/'.now()->format('Ymd_His_u').'-'.$action.'.dump';

        $result = $this->pg($connection->getConfig(), ['pg_dump', '-Fc', '-t', $prefix.'*', '-f', $file]);

        if (! $result->successful() || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new PluginLifecycleException("pg_dump failed or was empty for {$key}; aborting: ".trim($result->errorOutput()));
        }

        return ['file' => $file, 'skipped' => null];
    }

    /**
     * Restore a dump in one transaction. Returns false when there is nothing to restore.
     */
    public function restore(?string $file): bool
    {
        $connection = DB::connection($this->connection);
        if ($file === null || $connection->getDriverName() !== 'pgsql') {
            return false;
        }
        if (! is_file($file)) {
            throw new PluginLifecycleException("Backup {$file} is missing; cannot restore.");
        }

        $result = $this->pg($connection->getConfig(), [
            'pg_restore', '--single-transaction', '--exit-on-error', '--clean', '--if-exists', '--no-owner', $file,
        ]);
        if (! $result->successful()) {
            throw new PluginLifecycleException('pg_restore failed: '.trim($result->errorOutput()));
        }

        return true;
    }

    protected function pg(array $config, array $command)
    {
        $command[] = '--dbname='.(string) $config['database'];
        array_splice($command, 1, 0, [
            '-h', (string) ($config['host'] ?? '127.0.0.1'),
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? ''),
        ]);

        return Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->timeout(1800)->run($command);
    }
}
