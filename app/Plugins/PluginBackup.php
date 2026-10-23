<?php

namespace App\Plugins;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Plugin-only dumps before install/upgrade/purge (ADR-0001 D15, §8).
 * PostgreSQL only (`pg_dump -t '<prefix>*'`); other drivers (the SQLite test
 * DB) are skipped and return null.
 */
class PluginBackup
{
    public function dump(string $key, string $prefix, string $action): ?string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql' || $prefix === '') {
            return null;
        }

        $dir = storage_path("app/plugin-backups/{$key}");
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $file = $dir.'/'.now()->format('Ymd_His').'-'.$action.'.dump';

        $config = $connection->getConfig();
        $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])
            ->run([
                'pg_dump', '-Fc',
                '-h', (string) ($config['host'] ?? '127.0.0.1'),
                '-p', (string) ($config['port'] ?? 5432),
                '-U', (string) ($config['username'] ?? ''),
                '-t', $prefix.'*',
                '-f', $file,
                (string) $config['database'],
            ]);

        if (! $result->successful()) {
            throw new PluginLifecycleException("pg_dump failed for {$key}: ".trim($result->errorOutput()));
        }

        return $file;
    }
}
