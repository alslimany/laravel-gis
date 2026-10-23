<?php

namespace App\Plugins;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $result = $this->pg($connection->getConfig(), [self::bin('pg_dump'), '-Fc', '-t', $prefix.'*', '-f', $file]);

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
            self::bin('pg_restore'), '--single-transaction', '--exit-on-error', '--clean', '--if-exists', '--no-owner', $file,
        ]);
        if (! $result->successful()) {
            throw new PluginLifecycleException('pg_restore failed: '.trim($result->errorOutput()));
        }

        return true;
    }

    /**
     * Table names contained in a dump (`pg_restore -l`).
     *
     * @return list<string>
     */
    public function dumpTables(string $file): array
    {
        $result = Process::timeout((int) config('plugins.backup_timeout', 1800))->run([self::bin('pg_restore'), '-l', $file]);
        if (! $result->successful()) {
            throw new PluginLifecycleException('pg_restore -l failed: '.trim($result->errorOutput()));
        }

        return self::parseTableList($result->output());
    }

    /**
     * @return list<string>
     */
    public static function parseTableList(string $toc): array
    {
        // e.g. "215; 1259 16390 TABLE public ew_basins postgres"
        preg_match_all('/^\d+;\s+\d+\s+\d+\s+TABLE\s+(?!DATA\s)\S+\s+(\S+)/m', $toc, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * pgsql only: in ONE psql --single-transaction run, DROP the <prefix>_*
     * tables (pg_tables, schemaname = current_schema()) that the dump does not
     * contain, then replay the dump (pg_restore --clean --if-exists -f -
     * output, incl. COPY ... FROM stdin data and pg 17.6+ \\restrict lines,
     * which only psql understands). Drops are schema-qualified, quoted and use
     * CASCADE: it only reaches objects depending on plugin tables (FKs between
     * plugin tables, plugin views); core never FKs to plugin tables (ADR §6).
     * Runs out of process, so PHP's connection (search_path etc.) is unaffected.
     * Returns false on non-pgsql drivers (caller drops tables itself).
     */
    public function replaceAndRestore(string $prefix, ?string $file): bool
    {
        $connection = DB::connection($this->connection);
        if ($connection->getDriverName() !== 'pgsql') {
            return false;
        }
        if ($prefix === '') {
            throw new PluginLifecycleException('Refusing to drop tables without a data.prefix.');
        }

        $schema = (string) $connection->selectOne('select current_schema() as s')->s;
        $keep = $file === null ? [] : $this->dumpTables($file);
        $pluginTables = collect($connection->select(
            'select tablename from pg_tables where schemaname = current_schema()'
        ))->pluck('tablename')
            ->filter(fn ($t) => str_starts_with((string) $t, $prefix))
            ->values();
        $tables = $pluginTables->reject(fn ($t) => in_array($t, $keep, true))->values();

        $this->assertNoCoreDependents($prefix, $pluginTables->all());

        $quote = fn (string $id) => '"'.str_replace('"', '""', $id).'"';
        $sql = '';
        foreach ($tables as $table) {
            $sql .= 'DROP TABLE IF EXISTS '.$quote($schema).'.'.$quote((string) $table)." CASCADE;\n";
        }
        if ($file !== null) {
            $sql .= $this->restoreSql($file);
        }

        // Piped through stdin: the restore SQL (with plugin data) never touches disk.
        $result = $this->pg($connection->getConfig(), [
            self::bin('psql'), '-X', '-q', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-f', '-',
        ], $sql);
        if (! $result->successful()) {
            throw new PluginLifecycleException('Plugin restore (psql) failed and was rolled back: '.trim($result->errorOutput()));
        }

        return true;
    }

    /**
     * The dump as plain SQL (`pg_restore --clean --if-exists -f -`), so the
     * caller can run it inside its own transaction.
     */
    public function restoreSql(string $file): string
    {
        if (! is_file($file)) {
            throw new PluginLifecycleException("Backup {$file} is missing; cannot restore.");
        }
        $result = Process::timeout((int) config('plugins.backup_timeout', 1800))
            ->run([self::bin('pg_restore'), '--clean', '--if-exists', '--no-owner', '-f', '-', $file]);
        if (! $result->successful()) {
            throw new PluginLifecycleException('pg_restore -f - failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    /**
     * Run a pg_* client with the connection's host/port/user, sslmode and
     * socket support (a host starting with "/" is a Unix socket directory;
     * an empty host lets libpq use its default socket).
     */
    /**
     * <prefix>_* tables in the current schema (pg_tables).
     *
     * @return list<string>
     */
    public function prefixTables(string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        return collect(DB::connection($this->connection)->select(
            'select tablename from pg_tables where schemaname = current_schema()'
        ))->pluck('tablename')->filter(fn ($t) => str_starts_with((string) $t, $prefix))->values()->all();
    }

    /**
     * Abort before any DROP ... CASCADE / --clean if an object outside the
     * plugin prefix (core FK or view) depends on a plugin table.
     *
     * @param  list<string>  $tables
     */
    public function assertNoCoreDependents(string $prefix, array $tables): void
    {
        if ($tables === []) {
            return;
        }
        $connection = DB::connection($this->connection);
        $in = implode(',', array_fill(0, count($tables), '?'));
        $targets = "select c.oid from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = current_schema() and c.relname in ({$in})";

        $fks = $connection->select("select con.conname as name, src.relname as source, dst.relname as target
            from pg_constraint con
            join pg_class src on src.oid = con.conrelid
            join pg_class dst on dst.oid = con.confrelid
            where con.contype = 'f' and con.confrelid in ({$targets})", $tables);
        $views = $connection->select("select distinct v.relname as source, t.relname as target
            from pg_depend d
            join pg_rewrite r on r.oid = d.objid
            join pg_class v on v.oid = r.ev_class
            join pg_class t on t.oid = d.refobjid
            where d.classid = 'pg_rewrite'::regclass and d.refobjid in ({$targets}) and v.oid <> d.refobjid", $tables);

        $offenders = collect($fks)->map(fn ($r) => "foreign key {$r->name} on {$r->source} -> {$r->target}")
            ->merge(collect($views)->map(fn ($r) => "view {$r->source} -> {$r->target}"))
            ->filter(fn ($line) => ! preg_match('/^(foreign key \S+ on|view) '.preg_quote($prefix, '/').'/', $line))
            ->values();

        if ($offenders->isNotEmpty()) {
            throw new PluginLifecycleException('Refusing to restore: objects outside the plugin prefix depend on plugin tables: '
                .$offenders->implode('; ').'. Remove them (core must not depend on plugin tables, ADR §6) and retry.');
        }
    }

    /**
     * Binary path for a PostgreSQL client tool (config plugins.<tool>_binary).
     * pg_dump, pg_restore and psql must come from the same client package.
     */
    public static function bin(string $tool): string
    {
        return (string) config("plugins.{$tool}_binary", $tool);
    }

    /**
     * Check pg_restore and psql exist before a restore; logs their versions.
     *
     * @return array{pg_restore: string, psql: string}
     */
    public function preflight(): array
    {
        $versions = [];
        foreach (['pg_restore', 'psql'] as $tool) {
            try {
                $result = Process::timeout(30)->run([self::bin($tool), '--version']);
            } catch (\Throwable $e) {
                $result = null;
            }
            if (! $result || ! $result->successful()) {
                throw new PluginLifecycleException("{$tool} not found or not runnable (".self::bin($tool)."); set plugins.{$tool}_binary / PLUGIN_".strtoupper($tool).'_BINARY. Nothing was changed.');
            }
            $versions[$tool] = trim($result->output());
        }
        Log::info('plugin restore preflight', $versions);

        return $versions;
    }

    protected function pg(array $config, array $command, ?string $input = null)
    {
        $command[] = '--dbname='.(string) $config['database'];
        $args = [];
        $host = (string) ($config['host'] ?? '');
        if ($host !== '') {
            $args = ['-h', $host];
        }
        $args = array_merge($args, [
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? ''),
        ]);
        array_splice($command, 1, 0, $args);

        $env = array_filter([
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
            'PGSSLMODE' => (string) ($config['sslmode'] ?? ''),
            'PGSSLROOTCERT' => (string) ($config['sslrootcert'] ?? ''),
            'PGSSLCERT' => (string) ($config['sslcert'] ?? ''),
            'PGSSLKEY' => (string) ($config['sslkey'] ?? ''),
        ], fn ($v) => $v !== '');

        $process = Process::env($env)->timeout((int) config('plugins.backup_timeout', 1800));
        if ($input !== null) {
            $process = $process->input($input);
        }

        return $process->run($command);
    }
}
