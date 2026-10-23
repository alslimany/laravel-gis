# ADR-0001: Plugin host and plugin contract (Lumina GIS)

- Status: Proposed, for Web Dev + Code Review sign-off (#53)
- Inputs: Web Dev #54 prep, Lumina PM decisions 2026-10-23
- Epic: #52 · Blocks: A2, A3, A4
- Date: 2026-10-23
- Sources read: GitHub issues #52, #53, #59; Notion spec "Plugin/Package Architecture + Disaster Early Warning Plugin"; repo `main` files: `bootstrap/app.php`, `bootstrap/providers.php`, `composer.json`, `app/Models/User.php`, `app/Http/Middleware/{CheckRole,EnsureUserHasOrganization,HandleInertiaRequests}.php`, `database/migrations/*create_layers_table.php`, `config/dataimport.php`, `resources/js/Layouts/app/app-sidebar-layout.tsx`, and the repo tree.

## 1. Context
- Stack (composer.json): Laravel ^13, PHP ^8.3, Inertia ^3.3 + React/TSX (`resources/js/Pages`, `Layouts`), Sanctum, Predis, PHPUnit 12. `extra.laravel.dont-discover` is empty, so package auto-discovery is on. No `packages/` dir and no `repositories` entry yet.
- Providers: only `App\Providers\AppServiceProvider` is listed in `bootstrap/providers.php`. Routing is the `web`/`api`/`console` files in `bootstrap/app.php`. Middleware aliases: `role` (CheckRole → `User::hasAnyRole`) and `organization` (EnsureUserHasOrganization → `users.organization_id` required).
- Tenancy: **single org per user** (`users.organization_id`). Core tables (e.g. `layers`) carry `organization_id` FK with cascade delete. There is no global scope and no tenant package; scoping is explicit per query/policy (e.g. `DataImportPolicy`).
- Roles: `roles` + `role_user`; `isAdmin()` = role `admin`, which is **org-level**. There is **no platform super-admin** today (#52 confirms).
- Layers: a `layers` row points to a PostGIS `table_name`, and has GeoServer fields (`geoserver_layer_name`, `published`) and `metadata` JSON. Imports go through ogr2ogr (`config/dataimport.php`, prefix `import_`).
- Frontend: `HandleInertiaRequests::share()` spreads `ConsoleProps::base()`. The sidebar comes from the `AppSidebar` component. There are no extension slots yet.
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
| D2 | Org admin scoping | Add nullable `role_user.organization_id` FK now (minimal migration, backfilled from `users.organization_id`). "Org admin" = `admin` role row for that org. `isAdmin()` gains an org-aware variant; `UserPolicy` checks same org. | Roles are global today, so an admin of org A may reach org B (`users.*`). Fixing it before plugins add a second admin-gated surface is cheaper than later. |
| D3 | Route gating | Plugin routes **always registered**; `plugin:{key}` middleware gates per request. **404 for both** not-installed and disabled-for-org. | Keeps `route:cache` working; uniform 404 hides which plugins exist (PM decision, overrides prep's 404/403). |
| D4 | Install / uninstall / purge | Install runs plugin migrations via CLI (`plugin:install`) or a queued job only, never in a web request. Uninstall flips state, keeps data **indefinitely**, no auto-retention. Separate super-admin `plugin:purge {key}` with `pg_dump` backup + typed confirmation. | Avoids web timeouts / wrong DB user; data loss only by explicit, recoverable action. |
| D5 | Super-admin toggle | Super admin may toggle any org; every toggle by anyone is written to `plugin_events`. Plugins default **off** for new orgs. | Support/ops need it; audit keeps it accountable. |
| D6 | Manifest | `composer.json` → `extra.lumina` (no separate JSON file). | One source with Composer discovery; read from `installed.json`, cached. |
| D7 | Settings validation | **Now, minimal:** manifest may declare Laravel validation rules for settings; `PluginManager` validates on save. JSON-Schema/UI generation later. | Cheap now; prevents junk in `settings` jsonb that would be costly to clean later. |
| D8 | hello-plugin | `require-dev` only; never ships to prod. Used by tests. | Test fixture, not a product. |
| D9 | Tables / services | `installed_plugins`, `organization_plugins` (unique `organization_id`+`plugin_key`), `plugin_events`; cached `PluginManager`; gates `plugins.install`, `plugins.toggle`. | Matches Web Dev #54 plan. |
| D10 | Plugin API version | SemVer starting **1.0.0** (`config('lumina.plugin_api')`). | First public contract. |
| D11 | Core schema guard | PHPUnit test (`CoreSchemaUntouchedTest`), not CI-only (Actions disabled). | Runs everywhere. |
| D12 | Early Warning data | `ew_basins` and forecasts global, stored once; orgs subscribe to areas. DEM fetched by script, not committed. | Avoids duplicate data and repo bloat. |

## 1b. Web Dev confirmations (2026-10-23)

**D2 sequencing.** The `role_user.organization_id` change ships in **#66** (security fix, first, standalone): nullable column backfilled from `users.organization_id`, indexed; `UserPolicy` same-org check; cross-org 403 test. **#54 depends on #66** and adds only `installed_plugins`, `organization_plugins`, and `users.is_super_admin`. Exactly one migration touches `role_user`.

**D13 Frontend slot wiring (#55).**
- `PluginManager::enabledFor($org)` returns enabled plugin manifests.
- Manifest (`extra.lumina`) declares nav items: `key`, `label_en`, `label_ar`, `route` (name), `icon`, `order`, optional `permission`.
- `HandleInertiaRequests` shares a lazy `plugins` prop, cached per org at `plugins:org:{id}`, shaped `[{key, nav: [...]}]`; cache busted on toggle/install.
- `ConsoleProps` gains typed `plugins: PluginSlot[]`.
- `AppSidebar` renders hard-coded core nav, then plugin items sorted by `order`, each wrapped in an error boundary so a failing plugin can't break the sidebar.
- No per-org route registration; routes stay always-registered and gated by `plugin:{key}` (D3).
- Plugin pages resolve via a `resolvePageComponent` glob over `packages/*/resources/js/Pages`.

## 2. Decision
Plugins are **first-party Laravel packages** under `packages/<key>`, loaded by a Composer path repository. Each package declares its manifest under `extra.lumina` in its own `composer.json` and ships a service provider that extends a core `Lumina\Plugins\PluginServiceProvider` base class. The core adds:
1. A cached `App\Plugins\PluginManager` (singleton) that discovers manifests from `vendor/composer/installed.json` and keeps global install state in `installed_plugins`.
2. An `organization_plugins` table (unique `organization_id`+`plugin_key`) that holds per-org enablement and settings.
3. A platform super-admin flag (`users.is_super_admin`, boolean, guarded, artisan-only; not a role).
4a. Gates `plugins.install` (also uninstall) and `plugins.toggle`.
4. A gating middleware `plugin:<key>`, plus a scheduler/job guard.
5. Frontend slots fed by an Inertia shared prop `plugins` and a Vite-registered component map.

Code presence (composer) is not the same as **installed** (super admin ran migrations and marked it installed), which is not the same as **enabled for org X** (org admin toggle).

## 3. Manifest schema (`packages/early-warning/composer.json` → `extra.lumina`)
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
    { "slot": "sidebar.main", "label": "Early Warning", "icon": "cloud-rain",
      "route": "early-warning.index", "permission": "early-warning.view" }
  ],
  "frontend": {
    "entry": "resources/js/index.tsx",
    "slots": {
      "mapBuilder.panel": "EarlyWarningPanel",
      "layer.detail.tab": "RiskTimelineTab"
    }
  },
  "settings": { "schema": "config/settings.schema.json" },
  "scheduled": ["FetchForecast:hourly", "ComputeRisk:hourly"],
  "outbound_hosts": ["api.open-meteo.com"],
  "data": { "tables": ["ew_basins", "ew_forecasts", "ew_risk_assessments", "ew_alert_rules", "ew_alert_logs"], "global_tables": ["ew_forecasts"] }
}
```
`key` is kebab-case and immutable. `version` is SemVer. `core` is the SemVer range of the plugin API (separate from the app version; API starts at 1.0.0). The `extra.laravel.providers` entry auto-registers the provider; `extra.lumina.provider` must match it.

## 4. Service provider contract
```php
abstract class PluginServiceProvider extends ServiceProvider {
    abstract public function key(): string;            // must equal manifest key
    public function routes(): ?string { return null; } // path to plugin routes file
    public function migrationsPath(): ?string { return null; }
    public function schedule(Schedule $s): void {}     // wrapped by org/enabled guard
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
        // Migrations are NOT auto-loaded into `php artisan migrate`; they run only via plugin:install (CLI/queue).
    }
}
```
Rules:
- No edits to `routes/web.php`.
- No core-table `Schema::table` calls, enforced by a PHPUnit test (`CoreSchemaUntouchedTest`, core-column snapshot before/after install).
- Integration happens only through public core services: Layer creation/import, GeoServer publish, Webhook, queue, and Organization/Role.

## 5. Gating
- **Super admin:** `users.is_super_admin` (migration in core), not in `$fillable`, granted/revoked only by `php artisan lumina:make-super-admin {email} [--revoke]`. A super admin may also belong to an org. Super-admin routes live at `/platform/extensions` behind a `super-admin` middleware (403). The super admin can install, upgrade and uninstall plugins, and can toggle any org (audited).
- **Org admin:** `role_user.organization_id` scoped `admin` role (see Decision D2) plus `organization`. The admin can enable or disable an installed plugin for **their own** org at `/organization/extensions` and edit its settings. Plugins default **off** for new orgs.
- **Request:** routes are always registered; the `plugin:<key>` middleware returns **404** both when the plugin is not installed and when it is not enabled for `auth()->user()->organization_id` (JSON 404 for api/*), so existence is not leaked. Lookups go through the cached `PluginManager` (`plugins:installed`, `plugins:org:{id}`), busted by model observers on change.
- **Jobs/scheduler:** the plugin `schedule()` runs global jobs only when the plugin is installed. Per-org jobs iterate `Plugin::enabledOrganizations($key)`. Per-org jobs use `PluginManager::enabledOrganizations($key)`. Every queued job carries `organization_id` and re-checks enablement in `handle()`.
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
  - Every org-owned plugin table has `organization_id` plus a `BelongsToOrganization` trait (a global scope from the current user's org, bypassable for super admin and console).
  - Global reference data has no `organization_id` and is read-only to orgs. For Early Warning, **basins (`ew_basins`) and forecasts are global, stored once**; orgs subscribe to areas via an org-scoped subscription table.
  - Large source data (DEM) is downloaded by a script, never committed.
- **Layers:** plugin outputs (basins, risk map) are normal `layers` rows with `metadata.plugin = "early-warning"`. They are not new columns on `layers`.
- **Disable:** keeps all data. Plugin layers stay as ordinary layers, which keeps the optional rule: core still renders them.
- **Uninstall (super admin):**
  - Uninstall flips state only (routes 404, jobs off); tables and `organization_plugins` rows are **kept indefinitely**. No auto-retention.
  - Purge is a separate super-admin artisan command `plugin:purge {key}`: requires the plugin uninstalled, takes a `pg_dump` of its prefixed tables to storage, needs a typed-key confirmation, then runs `down()` migrations. Audited.

## 7. Frontend slots
- *Slot wiring through `ConsoleProps::base()` / `AppSidebar` nav is to be confirmed with Web Dev (#55).*
- The shared Inertia prop `enabledPlugins: [{key, version, nav[], slots{}}]` contains enabled plugins only, and is added in `HandleInertiaRequests::share`.
- `resources/js/plugins/registry.ts` uses `import.meta.glob('../../../packages/*/resources/js/index.tsx')` to lazy-load plugin bundles. A plugin's `index.tsx` default-exports a `{ slots: Record<string, React.ComponentType>, pages: Record<string, ...> }`.
- `<PluginSlot name="sidebar.main" />` and friends render nothing when there are no entries. Initial slots: `sidebar.main`, `mapBuilder.panel`, `mapBuilder.toolbar`, `layer.detail.tab`, `organization.settings.section`, `dashboard.widget`.
- Plugin pages are Inertia pages resolved with a `<key>::Page/Name` prefix in the `app.tsx` resolver.
- Each slot is wrapped in an error boundary, so a plugin crash cannot break core pages.
- Build: a single Vite build (the plugins are first-party). There is no runtime remote JS.

## 8. Lifecycle
| Step | Who | What happens |
|---|---|---|
| Add code | Dev/ops | `composer require lumina/early-warning:@dev` (path repo), deploy |
| Install | Super admin | Validate manifest + `core` range → run plugin migrations (CLI `plugin:install` or queued job, never in-request) → `onInstall()` → `installed_plugins.status=installed` |
| Enable | Org admin (or super admin, audited) | `organization_plugins.enabled=true` → `onEnable($org)` → cache bust |
| Disable | Org admin | `enabled=false` → `onDisable($org)`; routes 404, jobs skip; data kept |
| Upgrade | Super admin | New code deployed → registry sees version > installed → maintenance flag for that plugin → migrate (CLI/queue) → `installed_plugins.version` updated; failure → `status=failed`, plugin gated off for all |
| Uninstall | Super admin | Gate off for all orgs → `status=uninstalled`, `uninstalled_at` set; data kept. Purge only via `plugin:purge` |

All lifecycle actions are written to an audit log (`plugin_events`, or `ProjectActivity`-style).

## 9. Versioning / compatibility
- Plugin SemVer. The manifest `core` constraint is checked against `config('lumina.plugin_api')`, starting at `1.0.0`. An incompatible plugin cannot be installed or upgraded, and a running one is gated off with an admin banner.
- Migrations must be forward-only and additive within a minor version. Breaking schema changes need a major version bump.
- The contract interface itself is versioned. Deprecations keep one minor version of overlap.

## 10. Security risks
| Risk | Mitigation |
|---|---|
| Cross-org data leak in plugin queries/jobs | `BelongsToOrganization` scope + jobs carry org id + PHPUnit tenancy tests per plugin |
| Super-admin escalation | Flag only via artisan; not mass-assignable; audit log; never settable by org admin UI |
| Plugin code is full-trust PHP | First-party/reviewed packages only; no upload-a-zip install; CR gate |
| Core table tampering | Migration lint test forbidding `Schema::table('<core>')`; DB role review |
| SSRF / outbound calls (Open-Meteo, NASA) | Manifest `outbound_hosts` allowlist enforced in a shared HTTP client; timeouts |
| Public token endpoints (Phase D) | Scoped, revocable, hashed tokens (Sanctum abilities); rate limits |
| GeoServer SQL views | Parameterised views; no user SQL; org filter in view |
| Frontend XSS / crash | Error boundaries; no `dangerouslySetInnerHTML`; same CSP |
| Existence disclosure | 404 for both not-installed and disabled |
| Cross-org admin reach (global `admin` role on `users.*`) | `role_user.organization_id` scoping + `UserPolicy` same-org check (D2) |
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

## 12. Open questions (remaining)
1. Open-Meteo: non-commercial use, free plan OK for #60 (relayed by PM 2026-10-23). Production outbound network access still to confirm.
2. Pilot basin: **Tripoli wadis** (decided by Abdullah, relayed by PM; #59).
3. `ConsoleProps` / `AppSidebar` slot wiring: to be confirmed with Web Dev before #55.
