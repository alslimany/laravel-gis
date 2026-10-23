<?php

return [
    /*
    | Cache store for the global plugin lifecycle lock (install, upgrade,
    | uninstall, purge, rollback). It MUST be shared by every app server and
    | queue worker: use "database" (cache_locks table) or "redis". "array" and
    | "file" only lock within one process/host and are for tests/dev.
    */
    'lock_store' => env('PLUGIN_LOCK_STORE', 'database'),

    // pg_dump / pg_restore process timeout (seconds).
    'backup_timeout' => (int) env('PLUGIN_BACKUP_TIMEOUT', 1800),

    // Lock TTL (seconds) so a crashed process can't hold it forever. Must be
    // well above backup_timeout + migration time (dump + migrate + restore):
    // default 4h vs a 30 min dump timeout.
    'lock_seconds' => (int) env('PLUGIN_LOCK_SECONDS', 14400),

    // Seconds to wait for the lock before failing.
    'lock_wait' => (int) env('PLUGIN_LOCK_WAIT', 5),

    /*
    | PostgreSQL client tools for plugin backup/restore. pg_dump, pg_restore
    | and psql MUST come from the same client package/version (and be >= the
    | server major), e.g. all from /usr/lib/postgresql/17/bin. plugin:rollback
    | runs a `--version` preflight and logs both versions before restoring.
    */
    'pg_dump_binary' => env('PLUGIN_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('PLUGIN_PG_RESTORE_BINARY', 'pg_restore'),
    'psql_binary' => env('PLUGIN_PSQL_BINARY', 'psql'),
];
