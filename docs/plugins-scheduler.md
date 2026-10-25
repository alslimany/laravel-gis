# Plugin scheduler (A4)

Installed plugins register jobs from their provider `schedule()` method. Core wires this in `bootstrap/app.php` via `PluginManager::registerSchedules()`. Uninstalled plugins contribute nothing. Per-org work fans out with `PluginManager::enabledOrganizations($key)` inside those jobs (see Early Warning `EvaluateAlertsJob` / `ComputeRiskJob`).

## Docker Compose

Both `docker-compose.yml` (local) and `docker-compose.production.yml` include a `scheduler` service:

```bash
php artisan schedule:work
```

Start it with the rest of the stack (`docker compose up -d`). Pair it with the `queue` / `queue-worker` service so dispatched jobs actually run.

## Laravel Herd (no Compose scheduler)

Herd does not run `schedule:work` for you. On macOS, add a user cron (or a LaunchAgent) that hits the Herd PHP binary every minute:

```cron
* * * * * cd /Users/you/Herd/gis-app && php artisan schedule:run >> /dev/null 2>&1
```

Use the same PHP Herd uses for the site (`which php` inside the project, or Herd’s linked binary). Keep a queue worker running separately (`php artisan queue:work` in a terminal, or a process manager).

Verify registration:

```bash
php artisan schedule:list
```

With Early Warning installed you should see named events such as `early-warning:fetch-forecasts`.
