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
];
