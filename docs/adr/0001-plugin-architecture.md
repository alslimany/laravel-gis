# ADR-0001: Plugin host and plugin contract (Lumina GIS)

- Status: Proposed, rev 5, Code Review APPROVE on d776e76 (notes folded in) (rollback by batch, plugin-scoped restore) (#53)
- Inputs: Web Dev #54 prep, Lumina PM decisions 2026-10-23, Code Review of 24e45de and 8599f66
- Epic: #52 · Blocks: A2, A3, A4
- Date: 2026-10-23
- Sources read: GitHub issues #52, #53, #59; Notion spec "Plugin/Package Architecture + Disaster Early Warning Plugin"; repo `main` files: `bootstrap/app.php`, `bootstrap/providers.php`, `composer.json`, `app/Models/User.php`, `app/Http/Middleware/{CheckRole,EnsureUserHasOrganization,HandleInertiaRequests}.php`, `database/migrations/*create_layers_table.php`, `config/dataimport.php`, `resources/js/Layouts/app/app-sidebar-layout.tsx`, `resources/js/app.tsx`, `routes/web.php`, `app/Policies/UserPolicy.php`, `app/Http/Controllers/{UserController,SolutionPackController}.php`, `app/Jobs/*`, and the repo tree.

## 1. Context
- Stack (composer.json): Laravel ^13, PHP ^8.3, Inertia ^3.3 + React/TSX (`resources/js/Pages`, `Layouts`), Sanctum, Predis, PHPUnit 12. `extra.laravel.dont-discover` is empty, so package auto-discovery is on. No `packages/` dir and no `repositories` entry yet.
- Providers: only `App\Providers\AppServiceProvider` is listed in `bootstrap/providers.php`. Routing is the `web`/`api`/`console` files in `bootstrap/app.php`. Middleware aliases: `role` (CheckRole → `User::hasAnyRole`) and `organization` (EnsureUserHasOrganization → `users.organization_id` required).
- Tenancy: **single org per user** (`users.organization_id`). Core tables (e.g. `layers`) carry `organization_id` FK with cascade delete. There is no global scope, no `BelongsToOrganization` trait and no tenant package in the repo today (verified: no `addGlobalScope` anywhere in `app/`); scoping is explicit per query/policy (e.g. `DataImportPolicy`). Existing jobs (`app/Jobs/Process*Job`, `PublishLayerToGeoServer`, ...) receive models and do not re-scope by org.
- Roles: `roles` + `role_user`; `isAdmin()` = role `admin`, which is **org-level**. There is **no platform super-admin** today (#52 confirms).
- Layers: a `layers` row points to a PostGIS `table_name`, and has GeoServer fields (`geoserver_layer_name`, `published`) and `metadata` JSON. Imports go through ogr2ogr (`config/dataimport.php`, prefix `import_`).
- Frontend: `HandleInertiaRequests::share()` spreads `ConsoleProps::base()`. The sidebar comes from the `AppSidebar` component. `resources/js/app.tsx` resolves pages with an eager `import.meta.glob('./Pages/**/*.{tsx,jsx}')`. There are no extension slots yet.
- Product rules (#52/#53/Notion):
  - Plugins never modify core tables.
  - Plugins use their own route files.
  - Connections stay optional, and no core feature may depend on a plugin.
  - The super admin installs plugins and org admins enable them.
  - Disabling a plugin keeps its data.

## 1a. Decisions (settled for #54)
| # | Question | Decision | Rationale |
|---|---|---|---|
| D1 | Super admin | `users.is_super_admin` boolean, default false, indexed, **not fillable**, set/revoked only by `php artisan lumina:make-super-admin {email}`. A super admin may also belong to an org. | A role row is too easy to grant via existing role UIs and `admin`/`super-admin` are confusable; artisan-only keeps escalation off the web surface. |
| D2 | Org admin scoping | Add nullable `role_user.organization_id` FK now (minimal migration, backfilled from `users.organization_id`). "Org admin" = `admin` role row for that org; `hasRole()`/`CheckRole` gain an org-aware check (`hasRoleInOrg('admin', $org)`). Remove `organization_id` from `User::$fillable`. | `UserPolicy` **already** checks same org on view/update/delete/restore, so `users.edit/update/destroy` are not the gap. The real gaps (verified in code): (a) `role:admin` (`CheckRole` → global `hasAnyRole`) guards routes that have **no policy**, e.g. the `solution-packs.*` group and `users.index`, whose org filter is `->when($user->organization_id, ...)` so an admin with a null org lists every user; (b) `organization_id` is mass-assignable on `User`, so any `fill($request->...)` path can move a user (or an admin) into another org. #66 targets exactly (a) and (b). |
| D3 | Route gating | Plugin routes **always registered**; `plugin:{key}` middleware gates per request. **404 for both** not-installed and disabled-for-org. | Keeps `route:cache` working; uniform 404 hides which plugins exist (PM decision, overrides prep's 404/403). |
| D4 | Install / uninstall / purge | Install runs plugin migrations via CLI (`plugin:install`) or a queued job only, never in a web request. Uninstall flips state, keeps data **indefinitely**, no auto-retention. Separate super-admin `plugin:purge {key}` with `pg_dump` backup + typed confirmation. | Avoids web timeouts / wrong DB user; data loss only by explicit, recoverable action. |
| D5 | Super-admin toggle | Super admin may toggle any org; every toggle by anyone is written to `plugin_events`. Plugins default **off** for new orgs. | Support/ops need it; audit keeps it accountable. |
| D6 | Manifest | `composer.json` → `extra.lumina` (no separate JSON file). | One source with Composer discovery; parsed from `vendor/composer/installed.json` once and cached (see §5 Registry cache). |
| D7 | Settings validation | **Now, minimal:** manifest declares Laravel validation rules for settings under `extra.lumina.settings.rules`; `PluginManager` validates on save. This is the **only** settings source; there is no JSON Schema file. JSON-Schema/UI generation later. | Cheap now; prevents junk in `settings` jsonb that would be costly to clean later. |
| D8 | hello-plugin | `require-dev` only; never ships to prod. Used by tests. | Test fixture, not a product. |
| D9 | Tables / services | `installed_plugins`, `organization_plugins` (unique `organization_id`+`plugin_key`), `plugin_events`; cached `PluginManager`; gates `plugins.install`, `plugins.toggle`. | Matches Web Dev #54 plan. |
| D10 | Plugin API version | SemVer starting **1.0.0** (`config('lumina.plugin_api')`). | First public contract. |
| D11 | Core schema guard | PHPUnit test (`CoreSchemaUntouchedTest`), not CI-only (Actions disabled). It is a **test-time snapshot only** (core columns/indexes before vs after installing every plugin on a test DB); it is not a runtime or DB-permission control. | Runs everywhere; cheap. No separate DB-role restriction is proposed (single app DB user; plugins are first-party and code-reviewed). |
| D12 | Early Warning data | `ew_basins` and forecasts global, stored once; orgs subscribe to areas. DEM fetched by script, not committed. | Avoids duplicate data and repo bloat. |
| D13 | Frontend slot wiring | Merged into §1b (Web Dev confirmations) and §7; kept as a number for traceability. | Settled there. |
| D14 | Queue/console tenancy | `App\Tenancy\CurrentOrganization` (scoped singleton) is the single source of the current org for the `BelongsToOrganization` scope. HTTP: the existing `organization` middleware (`EnsureUserHasOrganization`) also sets `CurrentOrganization` from `auth()->user()->organization_id` and runs **before** `plugin:{key}`. Jobs: the `SetsOrganizationContext` job middleware sets it from the job's `organization_id`. **Fail closed:** if no org is set, scoped queries throw `MissingOrganizationContext` (no console bypass). `CurrentOrganization::runUnscoped(fn ...)` (logged) is allowed **only** in jobs marked `GlobalJob` and in callbacks registered in a provider's `schedule()`; an arch test bans it in controllers and in any `OrganizationScopedJob`. Every job class under `packages/*` must implement `OrganizationScopedJob` or the explicit `GlobalJob` marker (arch test). **Scope:** D14 covers plugin models only; core models (e.g. `Layer`) stay unscoped and keep their existing explicit queries and policies. | Workers run in console; a "bypass in console" scope would make every queued job unscoped. |
| D15 | Plugin migrations | Run with `php artisan migrate --path=<plugin migrations> --realpath --force` in their **own batch**, always **after** core `migrate`; each batch number is appended to `installed_plugins.meta.batches`. Rollback is `php artisan migrate:rollback --batch=<n> --force` for each recorded plugin batch, **newest first** (never `--step`, which counts the latest batches DB-wide). Before install/upgrade a **plugin-only** dump (`pg_dump -t '<prefix>_*'`) is taken; restore on `down()` failure touches only plugin-prefixed tables. The full DB dump is outage-level DR only (§8). | Plugin batches stay isolated from core batches; a failed plugin rollback can never revert other orgs' core writes. |

## 1b. Web Dev confirmations (2026-10-23)

**D2 sequencing.** The `role_user.organization_id` change ships in **#66** (security fix, first, standalone): nullable column backfilled from `users.organization_id`, indexed; org-aware `CheckRole`/`hasRoleInOrg` for policy-less `role:admin` routes (`solution-packs.*`, `users.index` with a hard org filter, no `when()`); `organization_id` removed from `User::$fillable`; `UserPolicy` keeps its existing same-org check; cross-org 403/404 tests for those routes and a mass-assignment test. **#54 depends on #66** and adds only `installed_plugins`, `organization_plugins`, and `users.is_super_admin`. Exactly one migration touches `role_user`.

**D13 Frontend slot wiring (#55).**
- `PluginManager::enabledFor($org)` returns enabled plugin manifests.
- Manifest (`extra.lumina`) declares nav items: `key`, `label_en`, `label_ar`, `route` (name), `icon`, `order`, optional `permission`.
- `HandleInertiaRequests` shares a lazy `plugins` prop, cached per org at `plugins:org:{id}`, shaped `[{key, nav: [...]}]`; cache busted on toggle/install.
- `ConsoleProps` gains typed `plugins: PluginSlot[]`.
- `AppSidebar` renders hard-coded core nav, then plugin items sorted by `order`, each wrapped in an error boundary so a failing plugin can't break the sidebar.
- No per-org route registration; routes stay always-registered and gated by `plugin:{key}` (D3).
- Plugin pages resolve through the **lazy** `<key>::Page/Name` resolver in §7 (this replaces the earlier `packages/*/resources/js/Pages` glob idea; one mechanism only).

## 2. Decision
Plugins are **first-party Laravel packages** under `packages/<key>`, loaded by a Composer path repository. Each package declares its manifest under `extra.lumina` in its own `composer.json` and ships a service provider that extends a core `Lumina\Plugins\PluginServiceProvider` base class. The core adds:
1. A cached `App\Plugins\PluginManager` (singleton) that discovers manifests from `vendor/composer/installed.json` and keeps global install state in `installed_plugins`.
2. An `organization_plugins` table (unique `organization_id`+`plugin_key`) that holds per-org enablement and settings.
3. A platform super-admin flag (`users.is_super_admin`, boolean, guarded, artisan-only; not a role).
4. Gates `plugins.install` (also uninstall) and `plugins.toggle`.
5. A gating middleware `plugin:<key>`, plus a scheduler/job guard and the `CurrentOrganization` context (D14).
6. Frontend slots fed by the Inertia shared prop `plugins` (D13) and lazily loaded plugin bundles (§7).

Code presence (composer) is not the same as **installed** (super admin ran migrations and marked it installed), which is not the same as **enabled for org X** (org admin toggle).

## 3. Manifest schema (`packages/early-warning/composer.json` → `extra.lumina`)
D13 (nav) and D7 (settings) are the source for this schema.
```json
{
  "key": "early-warning",
  "name": "Disaster Early Warning",
  "version": "0.1.0",
  "core": "^1.0",
  "provider": "Lumina\\EarlyWarning\\Providers\\EarlyWarningServiceProvider",
  "description": "Forecast-based flood / heavy-rain decision support (advisory, not official).",
  "permissions": ["early-warning.view", "early-warning.manage"],
  "nav": [
    { "key": "early-warning", "label_en": "Early Warning", "label_ar": "الإنذار المبكر",
      "route": "early-warning.index", "icon": "cloud-rain", "order": 100,
      "permission": "early-warning.view" }
  ],
  "frontend": {
    "entry": "resources/js/index.tsx",
    "slots": {
      "mapBuilder.panel": "EarlyWarningPanel",
      "layer.detail.tab": "RiskTimelineTab"
    }
  },
  "settings": {
    "rules": {
      "rain_threshold_mm": ["required", "numeric", "min:0", "max:500"],
      "notify_emails": ["nullable", "array"],
      "notify_emails.*": ["email"]
    },
    "defaults": { "rain_threshold_mm": 50 }
  },
  "outbound_hosts": ["api.open-meteo.com"],
  "data": { "tables": ["ew_basins", "ew_forecasts", "ew_subscriptions", "ew_risk_assessments", "ew_alert_rules", "ew_alert_logs"], "global_tables": ["ew_basins", "ew_forecasts"] }
}
```
- `key` is kebab-case and immutable. `version` is SemVer. `core` is the SemVer range of the plugin API (separate from the app version; API starts at 1.0.0). The `extra.laravel.providers` entry auto-registers the provider; `extra.lumina.provider` must match it.
- `nav` items are exactly the D13 shape (`key`, `label_en`, `label_ar`, `route`, `icon`, `order`, optional `permission`) and render in the `sidebar.main` slot after core nav.
- `settings.rules` are Laravel validation rules (D7), validated by `PluginManager::saveSettings()`; `defaults` fill missing keys.
- **Scheduled tasks are not declared in the manifest.** The single place is the provider's `schedule(Schedule $s)` (§4).
- `outbound_hosts` is **advisory documentation only** (reviewed in CR, shown on the super-admin install screen). It is not a runtime allowlist; enforcement is the arch test in §10/§12.

## 4. Service provider contract
```php
abstract class PluginServiceProvider extends ServiceProvider {
    abstract public function key(): string;            // must equal manifest key
    public function routes(): ?string { return null; } // path to plugin routes file
    public function migrationsPath(): ?string { return null; }
    public function schedule(Schedule $s): void {}     // ONLY place scheduled tasks are declared; wrapped by installed/enabled guard
    public function onInstall(): void {}               // global seed
    public function onEnable(Organization $o): void {} // e.g. default AlertRules
    public function onDisable(Organization $o): void {}// stop jobs; keep data
    public function onUninstall(bool $purge): void {}
    public function healthCheck(): array { return []; }

    final public function boot(): void {
        // Always (route:cache safe; gating is per request):
        //   loadRoutesFrom wrapped in
        //   Route::middleware(['web','auth','organization',"plugin:{$key}"])->prefix("x/{$key}")->name("{$key}.")
        //   mergeConfigFrom, register policies/permissions, register scheduler callback (guarded)
        // Migrations are NOT auto-loaded into `php artisan migrate`; they run only via plugin:install/upgrade
        // (CLI/queue) as `migrate --path=<migrationsPath()> --realpath` in their own batch, after core (D15).
    }
}
```
Rules:
- No edits to `routes/web.php`.
- No core-table `Schema::table` calls, enforced by a PHPUnit test (`CoreSchemaUntouchedTest`, core-column snapshot before/after install).
- Integration happens only through public core services: Layer creation/import, GeoServer publish, Webhook, queue, and Organization/Role.
- **Optional-only rule:** core has no mandatory links to any plugin, no required core field depends on a plugin, every slot renders nothing when its plugin is off or not installed, and the full core test suite runs with **all plugins off** (§12).

## 5. Gating
- **Super admin:** `users.is_super_admin` (migration in core), not in `$fillable`, granted/revoked only by `php artisan lumina:make-super-admin {email} [--revoke]`. A super admin may also belong to an org. Super-admin routes live at `/platform/extensions` behind a `super-admin` middleware (403). The super admin can install, upgrade and uninstall plugins, and can toggle any org (audited).
- **Org admin:** `role_user.organization_id` scoped `admin` role (see Decision D2) plus `organization`. The admin can enable or disable an installed plugin for **their own** org at `/organization/extensions` and edit its settings. Plugins default **off** for new orgs.
- **Super admin on plugin routes (`/x/<key>/...`):** gating follows the user's **own** org like everyone else. A super admin whose org has the plugin enabled sees it; a super admin with **no org** is stopped by the `organization` middleware (redirect to org setup, as for any user without an org); a super admin whose org has the plugin disabled gets **404**. A super admin never sees another org's plugin data through plugin routes; cross-org support work happens only from `/platform/extensions` (install state and per-org toggles, audited in `plugin_events`), which never renders org data.
- **Request:** routes are always registered; the `plugin:<key>` middleware returns **404** both when the plugin is not installed and when it is not enabled for `auth()->user()->organization_id` (JSON 404 for api/*), so existence is not leaked. Lookups go through the cached `PluginManager` (`plugins:installed`, `plugins:org:{id}`), busted by model observers on change.
- **Registry cache:** the parsed manifest list from `vendor/composer/installed.json` is cached under `plugins:registry:{hash}` where `hash` is the file's mtime+size, so it is rebuilt only after `composer install`/deploy; `php artisan plugin:cache` / `plugin:clear` warm and clear it (run in deploy next to `route:cache`).
- **Jobs/scheduler (D14):** the plugin `schedule()` runs global jobs only when the plugin is installed. Per-org jobs are dispatched once per org from `PluginManager::enabledOrganizations($key)`.
  - Every per-org queued job implements `OrganizationScopedJob`, carries `organization_id`, and uses the `SetsOrganizationContext` job middleware, which sets `CurrentOrganization` before `handle()` and clears it after (also on failure), and re-checks that the plugin is installed and enabled for that org (otherwise the job is released/deleted, not run).
  - **Fail closed:** the `BelongsToOrganization` scope reads only `CurrentOrganization`; it has **no** console or super-admin bypass. A scoped query with no context throws `MissingOrganizationContext`. Global jobs (e.g. forecast fetch into global `ew_forecasts`) implement the `GlobalJob` marker and touch only global tables; only they (and `schedule()` callbacks) may wrap work in `CurrentOrganization::runUnscoped()`, which is logged (D14). Every job class in `packages/*` is either `OrganizationScopedJob` or `GlobalJob` (arch test).
  - Required test: a cross-org job test dispatches a job for org A with data in A and B, and asserts it reads/writes only A, and that the same job without an org context throws.
- **Policies:** plugin permissions (manifest `permissions`) map to org roles. The default is admin = manage, member = view.

## 6. Data ownership
- **Core tables (new):**
  - `installed_plugins(id, plugin_key string(64) unique, version, status enum[installed,upgrading,failed,uninstalled], installed_by, installed_at, uninstalled_at null, meta jsonb, timestamps)`
  - `organization_plugins(id, organization_id FK cascade, plugin_key, enabled bool default false, settings jsonb null, enabled_by, enabled_at, disabled_at, timestamps)`, **unique(organization_id, plugin_key)**, index(plugin_key, enabled); no FK to `installed_plugins` so rows survive uninstall/reinstall.
  - `plugin_events` audit log (actor, org, plugin_key, action, payload).
  - `role_user.organization_id` nullable FK (Decision D2).
- **Plugin tables:**
  - Names are prefixed `<short>_`, e.g. `ew_`.
  - They live in the plugin's migrations path and are tracked in the normal `migrations` table.
  - They may FK **to** core (`organization_id`, `layer_id`) with `cascadeOnDelete` for org deletion. Core never FKs to plugin tables.
- **Org scoping:**
  - Every org-owned plugin table has `organization_id` plus a new core `BelongsToOrganization` trait (added in #54): a global scope reading `CurrentOrganization` (D14), fail-closed, with no console or super-admin bypass; `creating` fills `organization_id` from the context.
  - Global reference data has no `organization_id` and is read-only to orgs. For Early Warning, **basins (`ew_basins`) and forecasts are global, stored once**; orgs subscribe to areas via an org-scoped subscription table.
  - Large source data (DEM) is downloaded by a script, never committed.
- **Layers:** plugin outputs (basins, risk map) are normal `layers` rows with `metadata.plugin = "early-warning"`. They are not new columns on `layers`.
- **Plugin-created layers after rollback/uninstall/purge:** on rollback or uninstall, `layers` rows with `metadata.plugin = <key>` are kept and marked `metadata.source_missing = true`; the UI shows them with a "source plugin unavailable" badge (core still renders whatever PostGIS table still exists). On reinstall/forward migration the flag is cleared. `plugin:purge` lists these layers in its confirmation and, after the typed-key confirm, deletes them (and their GeoServer publications) before dropping the plugin tables. No dangling rows remain after purge.
- **Disable:** keeps all data. Plugin layers stay as ordinary layers, which keeps the optional rule: core still renders them.
- **Uninstall (super admin):**
  - Uninstall flips state only (routes 404, jobs off); tables and `organization_plugins` rows are **kept indefinitely**. No auto-retention.
  - Purge is a separate super-admin artisan command `plugin:purge {key}`: requires the plugin uninstalled, takes a plugin-only `pg_dump -t '<prefix>_*'` to `storage/app/plugin-backups` (§8 Backups), needs a typed-key confirmation, then runs `down()` migrations. Audited.

## 7. Frontend slots
D13 is the source; this section only adds the component loading.
- **Prop:** one shared Inertia prop, `plugins` (lazy, cached per org at `plugins:org:{id}`), typed in `ConsoleProps` as `plugins: PluginSlot[]`, where `PluginSlot = { key, version, nav: NavItem[], slots: Record<string, string> }`. Enabled plugins only; empty array when none. No other plugin prop name is used.
- **Sidebar:** `AppSidebar` renders core nav, then `plugins[].nav` sorted by `order`, each item in an error boundary (D13).
- **Slots:** `resources/js/plugins/registry.ts` maps each plugin key to `import.meta.glob('../../../packages/*/resources/js/index.tsx')` (**lazy**, not eager). A plugin's `index.tsx` default-exports `{ slots: Record<string, React.ComponentType>, pages: Record<string, () => Promise<...>> }`. `<PluginSlot name="..." />` loads the bundle only for keys present in `plugins` and renders **nothing** when no enabled plugin fills the slot. Initial slots: `sidebar.main`, `mapBuilder.panel`, `mapBuilder.toolbar`, `layer.detail.tab`, `organization.settings.section`, `dashboard.widget`.
- **Pages (single mechanism):** plugin controllers render `Inertia::render('<key>::Page/Name')`. The `app.tsx` resolver keeps its core `./Pages/**` glob; names containing `::` are routed to the plugin registry, which lazy-imports that plugin's `pages` entry. There is no `packages/*/resources/js/Pages` glob.
- Each slot and plugin page is wrapped in an error boundary, so a plugin crash cannot break core pages.
- Build: a single Vite build (the plugins are first-party); plugin code is split into lazy chunks. There is no runtime remote JS.

## 8. Lifecycle
| Step | Who | What happens |
|---|---|---|
| Add code | Dev/ops | `composer require lumina/early-warning:@dev` (path repo), deploy |
| Install | Super admin | Core `migrate` done first → validate manifest + `core` range → plugin gated off → plugin-only `pg_dump` → `migrate --path=<plugin> --realpath --force` in its own batch (CLI `plugin:install` or queued job, never in-request), batch number appended to `installed_plugins.meta.batches` → `onInstall()` → `installed_plugins.status=installed` |
| Enable | Org admin (or super admin, audited) | `organization_plugins.enabled=true` → `onEnable($org)` → cache bust |
| Disable | Org admin | `enabled=false` → `onDisable($org)`; routes 404, jobs skip; data kept |
| Upgrade | Super admin | New code deployed → core `migrate` → registry sees version > installed → plugin gated off (`status=upgrading`) → plugin-only `pg_dump -t '<prefix>_*'` → `migrate --path=<plugin> --realpath --force` (new batch) → `installed_plugins.version` updated, new batch appended to `meta.batches`; failure → `status=failed`, plugin stays gated off for all |
| Rollback | Super admin | Plugin gated off for all orgs (`status=upgrading`) → redeploy previous plugin code → for each batch to undo in `installed_plugins.meta.batches`, **newest first**: `php artisan migrate:rollback --batch=<n> --force` (removes only that batch; core batches are never touched even if they ran later) → pop it from `meta.batches` → restore `installed_plugins.version`; plugin layers marked `source_missing` where their tables are gone. If `down()` is unsafe or fails: restore **only the plugin-prefixed tables** from the plugin-only dump (drop `<prefix>_*`, `pg_restore` that dump), never the full DB dump |
| Uninstall | Super admin | Gate off for all orgs → `status=uninstalled`, `uninstalled_at` set; data kept. Purge only via `plugin:purge` |

**Backups (D15).**
- **Routine (every install/upgrade/purge):** plugin-only `pg_dump -Fc -t '<prefix>_*'`. This is the only dump used for plugin restore, so restoring never rolls back core or other orgs' writes.
- **Full DB dump:** outage-level disaster recovery only (taken by the normal ops backup schedule), never restored for a plugin failure. It is not taken per plugin operation because the large `import_*` tables make it slow and big.
- **Size/time:** plugin-only dumps are proportional to the plugin tables (e.g. `ew_*`, mostly global basins/forecasts); the command logs dump size and duration to `plugin_events` and aborts if free disk space is below 2× the estimated size (`pg_total_relation_size` of the prefixed tables).
- **Location/retention:** `storage/app/plugin-backups/<key>/<timestamp>-<action>.dump` (local disk, not public); keep the last 5 per plugin and at least 30 days, pruned by `plugin:backups:prune` (scheduled daily in core). Purge dumps are kept 90 days.
- **Gating during dump + migrate:** the plugin is gated off for all orgs (`status=upgrading`, so `plugin:{key}` returns 404 and `SetsOrganizationContext` releases its jobs) from before the dump until migrate/rollback finishes; it returns to `installed` only on success.

All lifecycle actions (install, upgrade, rollback, enable, disable, uninstall, purge, settings change) are written to `plugin_events` (D9).

**Restore implementation notes (CR on d776e76):**
- **Naming rule:** every database object a plugin creates (tables, sequences, enum types, functions, views, indexes) must use the `<prefix>_` name, so `pg_dump -t '<prefix>_*'` captures it. Plugin enum types and functions are also listed in the manifest and dumped explicitly (`-t` for each, or a dedicated schema with `-n`); an arch/migration test fails on any unprefixed object.
- **Restoring with core FKs:** run `pg_restore --single-transaction --exit-on-error`. Before re-adding FKs to core tables (`organizations`, `layers`), detect orphaned rows whose core parent was deleted after the dump; by default abort and report them in `plugin_events`, with an explicit `--prune-orphans` flag to delete them first.
- **Reconcile state:** after a restore, rewrite the plugin's rows in `migrations` and `installed_plugins.meta.batches` to match the restored schema version, and record the result in `plugin_events`.

## 9. Versioning / compatibility
- Plugin SemVer. The manifest `core` constraint is checked against `config('lumina.plugin_api')`, starting at `1.0.0`. An incompatible plugin cannot be installed or upgraded, and a running one is gated off with an admin banner.
- Migrations must be forward-only and additive within a minor version. Breaking schema changes need a major version bump.
- The contract interface itself is versioned. Deprecations keep one minor version of overlap.

## 10. Security risks
| Risk | Mitigation |
|---|---|
| Cross-org data leak in plugin queries/jobs | Fail-closed `BelongsToOrganization` scope on `CurrentOrganization` (D14, plugin models only; core models keep their policies), `organization` middleware / job middleware set context, `runUnscoped()` restricted to `GlobalJob`/`schedule()`, cross-org HTTP and job tests per plugin |
| Super-admin escalation | Flag only via artisan; not mass-assignable; audit log; never settable by org admin UI |
| Plugin code is full-trust PHP | First-party/reviewed packages only; no upload-a-zip install; CR gate |
| Core table tampering | `CoreSchemaUntouchedTest` (test-time snapshot, D11) + code review; no DB-role control is claimed |
| SSRF / outbound calls (Open-Meteo, NASA) | `outbound_hosts` is advisory (reviewed in CR). Enforced by an arch test banning raw HTTP (`curl_*`, `file_get_contents('http`, `new GuzzleHttp\\Client`, `fsockopen`) in `packages/*`; plugins must use the `Http` facade with timeouts. No URLs from user input |
| Public token endpoints (Phase D) | Scoped, revocable, hashed tokens (Sanctum abilities); rate limits |
| GeoServer SQL views | Parameterised views; no user SQL; org filter in view |
| Frontend XSS / crash | Error boundaries; no `dangerouslySetInnerHTML`; same CSP |
| Existence disclosure | 404 for both not-installed and disabled |
| Cross-org admin reach (global `admin` role on policy-less `role:admin` routes; mass-assignable `users.organization_id`) | #66: org-scoped `role_user`, org-aware `CheckRole`, `organization_id` not fillable (D2) |
| Advisory misuse | Mandatory "decision support, not official warning" wording (Notion) |

## 11. Alternatives considered
| Option | Pros | Cons | Verdict |
|---|---|---|---|
| **A. First-party Composer path packages + registry (chosen)** | Native Laravel; isolates routes/migrations; matches Notion spec; testable | Needs a deploy to add code; full-trust PHP | **Chosen** |
| B. Feature flags inside core (`app/Features/EarlyWarning`) | Simplest | Violates "own package / don't touch core"; coupling grows | Rejected |
| C. Third-party plugin pkg (e.g. nwidart/laravel-modules) | Ready-made scaffolding | Extra dependency; doesn't model super-admin install vs per-org enable; fit with Laravel 13 not verified | Rejected for now |
| D. Separate microservice per plugin | Strong isolation, own deploy | Auth/tenancy/GeoServer duplication; ops burden for a small team | Revisit later |
| E. Runtime-uploaded plugins (zip/marketplace) | Self-serve | Remote code execution risk; build pipeline for JS | Rejected |
| F. Full multi-tenancy package (separate DB/schema per org) | Hard isolation | Large core refactor; out of scope | Rejected |

## 12. Testing strategy
| Layer | Test | Where |
|---|---|---|
| Core, plugins off | Full existing suite runs with **no plugin installed or enabled**; must stay green (optional-only rule) | default `phpunit` run |
| Schema guard | `CoreSchemaUntouchedTest`: snapshot core columns/indexes, install all plugins (incl. hello-plugin), compare | `tests/Feature/Plugins` |
| Gating | `plugin:{key}` returns 404 for not-installed, disabled, other-org; JSON 404 under `api/*`; super admin with no org / other org (§5) | `tests/Feature/Plugins` |
| Tenancy (HTTP) | Org A user cannot read/write org B plugin rows | per plugin |
| Tenancy (jobs) | Cross-org job test + missing-context throws (D14) | per plugin + core |
| Lifecycle | install → enable → disable → uninstall → purge with hello-plugin; own migration batch; **rollback-by-batch test where a core batch is migrated after the plugin batch** and `migrate:rollback --batch=<plugin batch>` reverts only plugin tables while the later core batch remains; restore-on-`down()`-failure touches only `<prefix>_*`; purge removes `metadata.plugin` layers, rollback marks them `source_missing`; every step writes `plugin_events` | `tests/Feature/Plugins` |
| Settings | D7 rules reject invalid settings | per plugin |
| Arch | No raw HTTP in `packages/*`; no `Schema::table` on core tables in plugin migrations; scheduled tasks only in `schedule()`; `runUnscoped()` banned in controllers and `OrganizationScopedJob` classes; every `packages/*` job implements `OrganizationScopedJob` or `GlobalJob` | `tests/Arch` (PHPUnit, file scan) |
| #66 | Cross-org 403/404 on `solution-packs.*`, `users.index`; `organization_id` mass-assignment ignored | `tests/Feature/Security` |
| Frontend | Sidebar with `plugins: []` equals core nav; failing plugin component is contained by its error boundary | Vitest (or manual QA until set up) |

## 13. Open questions (remaining)
1. Open-Meteo: non-commercial use, free plan OK for #60 (relayed by PM 2026-10-23). Production outbound network access still to confirm.
2. Pilot basin: **Tripoli wadis** (decided by Abdullah, relayed by PM; #59). Exact wadis and outlet points still open.
