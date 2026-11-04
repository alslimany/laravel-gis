# Database roles (least privilege)

**Story:** DG-0.1  
**ADR:** [ADR-0002 §3](../adr/0002-desktop-gis-connectivity.md#3-least-privilege-database-roles)  
**Script:** [`docker/postgres/roles.sql`](../../docker/postgres/roles.sql)

Gate **G1** (ADR-0002 §8 / DG-0.7): do **not** apply this script to Abdullah’s local demo database until he confirms the NCMCD demo is done. Wave A ships the script and this doc only.

## Privilege matrix

| Role | Login | Flags | Purpose | Typical grants |
|---|---|---|---|---|
| `lumina_migrator` | yes | `NOSUPERUSER NOCREATEDB NOCREATEROLE` | Owns **core** schema objects; runs `artisan migrate`, plugin install/purge migrations, and `PluginBackup` dumps that need object ownership context | `CREATE` on `public`; membership of `lumina_layer_owner` (so it can create `SECURITY DEFINER` helpers and reassign layer tables); owns core tables/sequences |
| `lumina_app` | yes | same | Laravel default connection after DG-0.3 | DML (`SELECT/INSERT/UPDATE/DELETE`) on core tables; DML on layer tables **after** provisioning grants them; `USAGE, CREATE` **only** on schema `staging`; `EXECUTE` on provisioning functions; **no** `CREATE` on `public`; must never be superuser (boot check in DG-0.3) |
| `lumina_layer_owner` | **no** | same | Owns every **layer feature table** in `public` | Owns layer tables; owns `SECURITY DEFINER` provisioning functions with a fixed `search_path` (DG-0.2); default privileges grant DML to `lumina_app` and `SELECT` to `lumina_backup` |
| `lumina_gs_org{id}` | yes | same | GeoServer PostGIS **datastore** login for one organization | `USAGE` on `public`; `SELECT` only on that org’s **published** layer tables; `INSERT/UPDATE/DELETE` only on tables that have **at least one** desktop edit grant; **no** access to core tables (`users`, `layers`, `organizations`, …) |
| `lumina_backup` | yes | same | Read-only `pg_dump` / reporting | `SELECT` on tables and sequences (core + layer via default privileges); no DML/DDL |

All five role kinds are created (or templated) in `roles.sql` with those flags. Passwords are **not** stored in the repo; set them out-of-band when applying on staging/production.

### What each role must not do

| Role | Must not |
|---|---|
| `lumina_migrator` | Be used as the web/queue runtime user; hold GeoServer datastore credentials |
| `lumina_app` | Be superuser; `CREATE` tables in `public`; own layer feature tables; connect as GeoServer |
| `lumina_layer_owner` | Have `LOGIN` (no direct sessions) |
| `lumina_gs_org{id}` | See another org’s tables; read/write core catalog/auth tables; get blanket DML on all of its org’s layers |
| `lumina_backup` | Insert/update/delete or run DDL |

## Why per-org GeoServer roles (not shared + RLS)

ADR-0002 §3 and §10 reject a **shared** GeoServer DB user with row-level security:

1. **Storage model already isolates by table.** Each layer is its own PostGIS table (`import_org{id}_…` / provisioned names). `GRANT`/`REVOKE` on those tables matches the tenant boundary exactly.
2. **RLS would not buy isolation for GeoServer.** Core multi-tenant tables are never exposed to GeoServer. RLS only helps on shared tables; layer feature data is not shared.
3. **GeoServer pool semantics.** The datastore user must see table and column metadata for everything in its pool ([G5] in ADR-0002). Scoping that pool to **one org’s tables** via a dedicated role keeps metadata leakage inside the org; a shared role + RLS still exposes other orgs’ relation names/columns to the pool user in ways that fight GeoServer’s assumptions.
4. **Defence in depth with WFS-T.** Even if GeoServer ACL misconfigures, `lumina_gs_org{id}` physically cannot write another org’s tables, core tables, or layers without an edit grant (ADR-0002 §5.3).

Layer-level read vs edit is still decided in Lumina (`dc_grants`) and synced as table `GRANT`s; GeoServer AuthKey roles handle *who* may call WFS on a published layer (ADR-0002 §5.1–5.2).

## PostGIS extension bootstrap

- **Create once as DBA/bootstrap** (superuser or a role with `CREATE` on the database that can install extensions):  
  `CREATE EXTENSION IF NOT EXISTS postgis;`
- The Laravel migration `database/migrations/0001_01_01_000000_enable_postgis_extension.php` stays **idempotent** (`IF NOT EXISTS`). After the extension exists, that migration succeeds **without** superuser (needed once `lumina_migrator` runs migrations — DG-0.3).
- Verify on **PostgreSQL 17 / PostGIS 3.5.x** during the upgrade wave (D3); avoid PostGIS 3.5.3 / 3.6.0 per ADR-0002 [E11].
- Extension ownership should remain with the bootstrap role (or a dedicated extension owner), not `lumina_app`.

## Tie-in to ADR-0002 §3 and follow-on stories

| Piece | Where it lives | Story |
|---|---|---|
| Role definitions + matrix | This doc + `roles.sql` | **DG-0.1** (this story) |
| `SECURITY DEFINER` promote/create helpers owned by `lumina_layer_owner` | Core SQL / migrations | **DG-0.2** (see below) |
| App connects as `lumina_app`; migrate as `lumina_migrator` | `.env` / config (local `.env` only by Abdullah) | DG-0.3 |
| Per-org datastore + table GRANT sync for edit grants | GeoServer + desktop-connect | DG-1.x / WFS-T path |
| Audit trigger / history still fail closed for all writers | Core | DG-1.2 |

ADR-0001 exception (ADR-0002 §7): DB roles and provisioning functions are **core**, not plugin-owned. Disabling `desktop-connect` must not remove isolation.

## DG-0.2 Layer-table provisioning (SECURITY DEFINER)

**Migration:** `database/migrations/2026_11_04_140000_create_lumina_layer_provisioning_functions.php`  
**PHP:** `App\Services\FeatureTableProvisioner`

### What ships

- `public.lumina_promote_layer_table(staging_name, target_name)` — `SECURITY DEFINER`, owned by `lumina_layer_owner`, fixed `search_path` (`pg_catalog, public, staging, pg_temp`). Moves `staging.<name>` → `public.<target>`, sets ownership, grants DML to `lumina_app` (and `SELECT` to `lumina_backup` when that role exists). Rejects unsafe names with the same regex as `FeatureTableProvisioner::assertSafeTableName` (`^[a-z][a-z0-9_]*$`) plus the reserved-name list.
- Soft hooks for future `lumina_attach_layer_audit` / `lumina_attach_layer_validation` (DG-1.2 / DG-1.3): called only if those procedures already exist.
- `FeatureTableProvisioner::createEmpty` uses staging → promote when the function exists.
- `DataImportService` / `ExcelImportService` write into `staging` and promote when the function exists.

### Fallback when roles are absent (local demo / default CI)

The migration **no-ops** unless both `lumina_layer_owner` and `lumina_app` exist. It never creates roles and never runs `roles.sql`.

`FeatureTableProvisioner::isPromoteAvailable()` is false → create-empty and imports keep the pre-DG-0.2 path (`CREATE TABLE` in the connection default schema). That is intentional until gate **DG-0.7** / cutover **DG-0.3**. Do **not** apply `roles.sql` to Abdullah’s NCMCD demo volume from this story.

### Still deferred (documented call sites)

| Call site | Status |
|---|---|
| `FeatureTableProvisioner::createEmpty` | Wired (promote when available) |
| `DataImportService` / `ExcelImportService` | Soft staging + promote when available |
| `AnalysisLayerSaver` | Still creates tables directly; wire through promote in a follow-up once roles are on a throwaway stack |
| `CoreLayerPublisher` (SQL views) | Still `CREATE VIEW` as the migrator/app connection; not a feature-table promote path |
| Full DoD “suite under `lumina_app`” | Blocked on DG-0.3 + DG-0.7 |

### Verifying on a throwaway DB

```bash
psql -v ON_ERROR_STOP=1 -f docker/postgres/roles.sql
php artisan migrate
DB_ROLES_TEST=1 php artisan test --filter=LayerTableProvisioningTest
```

## How to apply (staging / throwaway only)

1. Connect as the bootstrap superuser to a **non-demo** database.
2. Ensure PostGIS exists (see above).
3. `psql -v ON_ERROR_STOP=1 -f docker/postgres/roles.sql`
4. Set passwords for `lumina_migrator`, `lumina_app`, `lumina_backup`.
5. Create `lumina_gs_org{id}` roles from the template in the script when an org first publishes layers.
6. After ownership cutover, re-run grants / `ALTER DEFAULT PRIVILEGES` as needed (DG-0.3).

Optional: for a **brand-new** volume only, copy or symlink the script into `docker/postgres/init/` (mounted as `/docker-entrypoint-initdb.d` in production compose). Do **not** remount or recreate Abdullah’s existing `postgis-data` volume to pick this up.

## Privilege matrix test

`tests/Feature/Security/DbRolesPrivilegeMatrixTest.php` **skips** unless `DB_ROLES_TEST=1`. Default CI (`.github/workflows/tests.yml`) and local suites stay green without applying roles — that workflow does **not** set `DB_ROLES_TEST`.

Opt-in CI: `.github/workflows/db-roles.yml` runs a throwaway `postgis/postgis:17-3.5` service, applies `docker/postgres/roles.sql` **only inside that ephemeral container**, migrates, then runs `DbRolesPrivilegeMatrixTest` + `LayerTableProvisioningTest` with `DB_ROLES_TEST=1`. It never targets Abdullah’s local demo volume (DG-0.7 / G1).

When you have a local throwaway DB with `roles.sql` applied, run:

```bash
DB_ROLES_TEST=1 php artisan test --filter=DbRolesPrivilegeMatrixTest
```
