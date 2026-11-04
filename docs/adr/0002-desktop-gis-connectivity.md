# ADR-0002: Desktop GIS connectivity (OGC API Features, GeoServer WFS-T, GeoPackage) and ADR-0001 core exception

- Status: **Accepted** (2026-11-04)
- Decision record: owner accepted D1–D7 on 2026-11-04 (see §1a). A1–A4 remain **open** as PM-defaulted assumptions (overridable by Abdullah). Gate G1 / DG-0.7 (no local DB upgrade until NCMCD demo is done) still holds.
- Inputs: the research report `~/Herd/lumina-desktop-gis-connectivity-research.md` (2026-11-03); Abdullah's decisions relayed by Lumina PM (2026-11-03); the backlog `~/Herd/lumina-desktop-gis-backlog.md`
- Relates to: ADR-0001 (plugin host and contract), which this ADR makes a scoped exception to (§7)
- Date proposed: 2026-11-03 · Date accepted: 2026-11-04
- Package docs: [`packages/desktop-connect/README.md`](../../packages/desktop-connect/README.md) (DG-1.4 scaffold)
- Sources read:
  - repo `main` 271d516: `docker-compose*.yml`, `.env` (DB user only), `config/geoserver.php`, `app/Traits/HasGeoServerLayers.php`, `app/Services/{FeatureService,FeatureTableProvisioner,DataImportService,GeoServerService,ContentAccessService}.php`, `app/Http/Controllers/{ApiTokenController,Api/V1/FeatureApiController}.php`, `routes/api.php`, `database/migrations/*create_layers_table.php`, `docs/adr/0001-plugin-architecture.md`
  - GeoServer, Esri, QGIS and OGC docs listed in §12

## 1. Context
- Each org's GIS engineers need to connect QGIS and ArcGIS (Pro, and Online/Enterprise where possible) to Lumina's data, and to edit it.
- **Current state:**
  - One shared `layers` registry points to one PostGIS table per layer in `public` (`import_org{id}_…`).
  - Tenant scoping is done per query and policy in the app.
  - GeoServer 2.21.0 has an `org_{id}` workspace per org and one shared datastore.
  - The app connects as the `postgres` **superuser**.
  - Sanctum token `abilities` are stored but **never checked** (no `tokenCan` or ability middleware in `app/` or `routes/`).
  - There is no per-feature history.
  - PostgreSQL 13 / PostGIS 3.1 and GeoServer 2.21 are end-of-life.
- **What clients can do** (research report §3):
  - QGIS edits WFS-T, OGC API Features Part 4 (≥ 3.32) and GeoPackage.
  - ArcGIS Pro **cannot** edit WFS-T or OGC API Features [E1, E2], or plain PostgreSQL [E3]. It **can** edit GeoPackage [E4] and Esri feature services.

## 1a. Decisions (locked 2026-11-04)

**Accepted / locked (D1–D7)** — owner decision recorded 2026-11-04:

| # | Decision | Status |
|---|---|---|
| D1 | ArcGIS editing through **GeoPackage check-out/check-in**, plus a **1-week Esri FeatureServer spike** with pass/fail criteria | **Locked** (Abdullah) |
| D2 | Live edits come through **both** Lumina OGC API Features (Part 1+4) **and** GeoServer WFS-T | **Locked** |
| D3 | Upgrade to PostgreSQL 16/17, PostGIS 3.5.x (avoid 3.5.3/3.6.0 [E11]) and GeoServer 2.28.x | **Locked** |
| D4 | Desktop access is **deny-by-default**, with per-layer read/edit grants | **Locked** |
| D5 | **No direct PostGIS** access for desktop clients | **Locked** |
| D6 | Support both QGIS and ArcGIS | **Locked** |
| D7 | Security fixes first: a least-privilege DB role split and enforced Sanctum abilities | **Locked** |

**Open PM-defaulted assumptions (A1–A4)** — remain open; GIS Lead defaults stand until Abdullah overrides:

| # | Assumption | Status |
|---|---|---|
| A1 | Audit trigger, history table, `layers.public_id`/`alias` and the DB role split are **core migrations** (§7) | **Open** — PM default; not yet confirmed by Abdullah; overridable |
| A2 | MVP auth is per-layer API keys plus Basic app passwords; OAuth2 later | **Open** — PM default; overridable |
| A3 | Feature history kept 1 year, configurable per org | **Open** — PM default; overridable |
| A4 | ArcGIS Pro 3.x baseline | **Open** — PM default; overridable |

| # | Gate | Status |
|---|---|---|
| G1 | **Gate:** no upgrade or migration of Abdullah's local DB until he confirms the NCMCD demo is done | Confirmed constraint |

## 2. Decision (architecture)
```
QGIS ──OGC API Features P1+P4 (Bearer | ?key | Basic)──► desktop-connect plugin (Laravel) ─► FeatureService ─► PostGIS
QGIS ──WFS-T (?authkey)──────────────────────────────► GeoServer 2.28 ─(per-org datastore role)─────────────► PostGIS
ArcGIS Pro/AGOL ──OGC API P1 / WFS 2.0 / WMS / WMTS (read, ?authkey or ?key)──► same two endpoints
ArcGIS Pro ──GeoPackage check-out → edit → check-in (diff + conflicts)──► desktop-connect ─► FeatureService
GeoServer AuthKey ──GET /internal/geoserver/authkey/{key}──► Lumina (validates key, returns user + roles)
PostGIS: validation constraints + audit trigger (fail closed on missing actor) → lumina_feature_history
```
- The **`desktop-connect`** plugin (ADR-0001 contract, off by default, 404 when disabled) owns:
  - grants (`dc_grants`, `dc_grant_events`), credentials metadata and checkouts (`dc_checkouts`);
  - the OGC API routes, the GeoServer key-validation endpoint, GeoServer ACL sync, the access screen and the History tab UI.
- **Core** owns: the DB roles, the provisioning functions, `layers.public_id`/`alias`, Sanctum ability enforcement, DB validation constraints, the audit trigger and `lumina_feature_history`. See §7.

## 3. Least-privilege database roles
| Role | Login | Rights |
|---|---|---|
| `lumina_migrator` | yes | Owns core objects; used only by `migrate`, plugin lifecycle and `PluginBackup` |
| `lumina_app` | yes | DML on core tables; `CREATE` only in `staging`; `EXECUTE` on provisioning functions; **not superuser** (a boot check fails otherwise) |
| `lumina_layer_owner` | no | Owns every layer table; SECURITY DEFINER provisioning functions (fixed `search_path`) promote staging tables, set grants, and attach triggers and constraints |
| `lumina_gs_org{id}` | yes | GeoServer datastore login for one org. `SELECT` on that org's published tables; `INSERT/UPDATE/DELETE` **only on tables with at least one edit grant**; no core tables |
| `lumina_backup` | yes | Read-only, for dumps |

**Why per-org roles rather than shared + RLS:**
- Feature data is already one table per layer, so table GRANTs give exact tenant isolation with no per-row policy overhead.
- RLS would only matter for shared multi-tenant tables, and none are exposed to GeoServer.
- GeoServer requires the pool user to see all table metadata and all columns [G5]; this holds within the org's tables.

## 4. Sanctum ability enforcement
- An ability catalog (`layers:read`, `features:read|write`, `exports:read`, `dc:layer:{publicId}:read|edit`).
- Ability middleware on every `/api/v1` route; no default `['*']`; legacy `*` tokens are flagged in the UI.
- An arch test fails on any API route without ability middleware.
- Desktop keys are Sanctum tokens whose abilities are derived from the holder's `dc_grants`. Revoking a grant removes the ability, so no key reissue is needed.

## 5. WFS-T security design (WFS-T bypasses Lumina's `FeatureService` validation)
Seven layers of defence:

**5.1 Authentication: AuthKey with a web-service mapper backed by Lumina [G4]**
- Install the GeoServer `authkey` extension and put an AuthKey filter on the OWS filter chain.
- **Key provider: external web service.** The URL is `https://lumina.internal/internal/geoserver/authkey/{key}`, restricted to the GeoServer network and protected by a shared secret header or mTLS. GeoServer calls it with HTTP GET, substituting `{key}` [G4].
- Lumina validates the key: it must exist, not be revoked or expired, and the user must be active and in the org. It responds with JSON, e.g. `{"user":"lu_42","roles":"DC_L_01HX…_R,DC_L_01HY…_W"}`.
- **Username regex:** `^.*?"user"\s*:\s*"(lu_[0-9]+)".*$`. Only `lu_<digits>` can match, which also makes §5.4 injection-safe.
- **Roles:** an **AuthKEY WebService Body Response** user-group service with a Roles Regex extracts the role list. GeoServer turns it into `ROLE_DC_L_<publicId>_R|W` [G4].
- **Statelessness and revocation:**
  - Enable **"Challenge Anonymous Sessions"**, so each request is authenticated from the authkey and not from an earlier session.
  - Set the **internal user cache** TTL to ≤ 60 s [G4].
  - Revoking a key or grant, or deactivating a user, therefore reaches GeoServer within the TTL. A "flush" can also call the filter's Synchronize, which clears the security context cache [G4].
- Per the docs, authkey must be used over HTTPS, since the key is in the URL [G4].
- **To verify in DG-1.9:** whether the web-service mapper accepts non-UUID keys. The docs describe the key as a UUID [G4], so Lumina issues desktop keys in UUID format.
- Basic auth to GeoServer stays only for the internal admin account. QGIS and ArcGIS Pro both send `?authkey=` (ArcGIS Pro through "Custom request parameters" [E10]).

**5.2 Authorization: data security rules, deny-by-default [G2]**
- `layers.properties` (managed through REST `/rest/security/acl/layers`):
  - global `*.*.r=ADMIN`, `*.*.w=NO_ONE`;
  - `mode=hide`, so unauthorised layers are absent from capabilities [G2];
  - per published layer: `org_12.roads.r=ROLE_DC_L_<pid>_R,ROLE_DC_L_<pid>_W` and `org_12.roads.w=ROLE_DC_L_<pid>_W`. Write doesn't imply read [G2], so both are set.
- **Sync model:** static rules are written **per layer** on publish, unpublish and alias change. **Who** holds a role is decided at authentication time by Lumina's response (§5.1), so grant changes need **no** GeoServer rule sync.
- WFS service level is Transactional only where at least one edit grant exists.
- **Optional GeoFence** (embedded) replaces this if we later need per-request rules or `cqlFilterWrite` row limits [G6]. Note that GeoFence treats insert, update and delete as one `WFS.Transaction` request type, so it can't split them [G6]. Not needed for the MVP.

**5.3 Database privilege**
- Each org workspace gets its own PostGIS datastore using `lumina_gs_org{id}` (§3).
- Even if GeoServer were misconfigured, it physically can't write to another org's tables, to core tables, or to layers with no edit grant. Lumina re-syncs table GRANTs when the first edit grant for a layer is created or the last one removed.

**5.4 Edit attribution: JDBC session SQL [G5]**
- GeoServer runs custom SQL when it borrows a connection from the pool and when it returns it. `GSUSER` holds the authenticated GeoServer user, and the syntax is `${variableName,defaultValue}` [G5].
- Startup SQL:
  ```sql
  SELECT set_config('lumina.actor', '${GSUSER,anonymous}', false),
         set_config('lumina.source', 'geoserver', false);
  ```
  (Equivalent: `SET lumina.actor = '${GSUSER,anonymous}'`. `set_config` is used for symmetry with the app.)
- Close-up SQL:
  ```sql
  RESET lumina.actor; RESET lumina.source;
  ```
- The username is guaranteed to match `lu_[0-9]+` (§5.1), so the substitution can't break the quoting.

**5.5 Fail-closed audit trigger**
- A BEFORE INSERT/UPDATE/DELETE trigger raises an error if `current_setting('lumina.actor', true)` is null, empty or `anonymous`, or doesn't map to an active user in the layer's org.
- An AFTER trigger writes `lumina_feature_history` (actor, source, txid, old/new attributes, changed keys, geometry before/after).
- An unattributed WFS-T transaction therefore rolls back, and so does any other writer.

**5.6 Database-level validation parity**
Generated from the `layers` and `layer_fields` metadata, and regenerated when fields change:
- geometry typmod `geometry(<Type>, <SRID>)`, or CHECKs on `GeometryType(geom)` and `ST_SRID(geom)`;
- `CHECK (ST_IsValid(geom))`;
- `NOT NULL`, or a check trigger, for required attributes;
- CHECKs for coded-value domains and ranges.

Calculated fields and cross-feature rules stay app-only (a known gap, covered by §5.7).

**5.7 Nightly reconciliation (optional, recommended)**
- A scheduled job re-runs `FeatureService` validation on the features changed by non-app sources in the last 24 h.
- It flags violations (`dc_validation_flags`), shows them in the History tab and notifies the org admin. Nothing is auto-fixed.

## 6. OGC API Features and GeoPackage paths
- **OGC API (Laravel):** Part 1 read, plus Part 4 with POST/PUT/PATCH/DELETE advertised via OPTIONS. QGIS requires POST to enable editing [Q4]. ETag/`If-Match` gives optimistic locking [O1]. All writes go through `FeatureService` with `lumina.source=ogc`.
- **GeoPackage:** check-out exports `lumina_fid` and `lumina_row_version` plus a `lumina_checkout` table. Check-in computes a diff, detects conflicts by `row_version`, requires review, and applies through `FeatureService` with `lumina.source=gpkg`.
- **FeatureServer spike:** a throwaway branch with pass/fail criteria (backlog DG-3.1). It isn't adopted unless ArcGIS Pro 3.x can create, update and delete and save edits.

## 7. Exception to ADR-0001 ("plugins never modify core tables")
- **Scope of the exception** (A1, a PM default that Abdullah can override):
  - `layers.public_id` and `layers.alias`;
  - the `lumina_feature_history` table;
  - DB roles and provisioning functions;
  - validation constraints and the audit trigger on **layer feature tables**;
  - Sanctum ability enforcement.
- **Rule kept:** the `desktop-connect` plugin still never alters core tables. It owns only `dc_*` tables. All the items above ship as **core** migrations and services, in core batches before any plugin migration (ADR-0001 D15).
- **Rationale:** these are platform security and audit controls that must apply to every write path, including the web UI, the API and future plugins. Putting them in a plugin would let disabling the plugin switch off auditing and isolation.
- **ADR-0001 D11** (`CoreSchemaUntouchedTest`) stays valid: the core snapshot is taken after core migrations, and `desktop-connect` must not change it.
- **ADR-0001 D14 is unchanged:** core models stay unscoped. Tenant isolation for external clients comes from per-org DB roles plus Lumina's grant checks.

## 8. Upgrade constraints
- Pin images by digest: `postgis/postgis:17-3.5` (not 3.5.3 or 3.6.0 [E11]) and GeoServer 2.28.6.
- 2.28 is now in security-only maintenance, and 3.0.x is the active stable branch [G7]. Plan a follow-up.
- Backup with `pg_dump -Fc` plus `pg_dumpall --globals-only` plus a GeoServer data-dir tarball. Rollback means repointing to the untouched old volume and digests.
- **Gate G1:** Abdullah's local DB is not touched until he confirms the NCMCD demo is done.

## 9. Consequences
- **Positive:**
  - Every write path is attributed and audited.
  - Tenant isolation is enforced both in GeoServer and in PostgreSQL.
  - Lumina is the single source of truth for grants.
  - The app no longer holds superuser.
  - Token scopes actually mean something.
- **Negative:**
  - More moving parts: GeoServer extensions, per-org datastores, provisioning functions.
  - DB constraints must be kept in sync with `layer_fields`.
  - Up to 60 s revocation lag in GeoServer.
  - Keys in URLs can leak into logs, mitigated by HTTPS, expiry, per-layer scope and log redaction.

## 10. Alternatives considered
| Alternative | Why not (now) |
|---|---|
| OGC API only, no WFS-T | Rejected by D2 (Abdullah wants both) |
| Shared GeoServer DB role + RLS | Per-table GRANTs are exact with one-table-per-layer storage; RLS adds complexity for no gain |
| GeoServer users synced from Lumina (Basic auth, JDBC user service) | Duplicates credentials, makes revocation slower, and means storing passwords in GeoServer |
| `SET SESSION AUTHORIZATION ${GSUSER}` with per-user DB roles [G5] | Needs DB users kept in sync with GeoServer users [G5]; heavier than a session variable plus a per-org role |
| Direct PostGIS for desktop | Rejected by D5 |
| pg_featureserv / tipg / GeoServer OGC API for Part 4 | No released Part 4 support (research report §2) |

## 11. Testing strategy
- Privilege matrix tests per role.
- An arch test that every API route has ability middleware.
- Trigger tests: no actor → reject; each source is attributed.
- A constraint test per rule.
- GeoServer integration (docker): no grant → layer hidden; read grant → Transaction denied; edit grant → insert attributed; revoke → denied within the TTL; cross-org workspace isolation.
- Client matrix: QGIS LTR and latest, ArcGIS Pro 3.x (read, GeoPackage), AGOL read.

## 12. Sources
- [G2] GeoServer, Layer security (rules, `r/w/a`, catalog mode `hide`, default anonymous read): https://docs.geoserver.org/latest/en/user/security/layer/
- [G4] GeoServer, Key authentication module (web-service key provider, `{key}` placeholder, GET only, username regex, AuthKEY WebService Body Response user-group service with roles regex, Challenge Anonymous Sessions, internal user cache, Synchronize, HTTPS): https://docs.geoserver.org/latest/en/user/extensions/authkey/
- [G5] GeoServer, Custom SQL session start/stop scripts (`GSUSER`, `${variableName,defaultValue}`, pool-user guidance): https://docs.geoserver.org/main/en/user/data/database/sqlsession/
- [G6] GeoServer, GeoFence REST API (rules: service, request, layer, access ALLOW/DENY/LIMIT, `cqlFilterWrite`): https://docs.geoserver.org/latest/en/user/extensions/geofence-server/rest/ · single-`WFS.Transaction` limitation: https://discourse.osgeo.org/t/geoserver-users-restricting-wfs-t-to-certain-transactions/48207
- [G7] GeoServer support windows: https://endoflife.date/geoserver · https://github.com/geoserver/geoserver/wiki/Release-Schedule
- [E1] Esri, ArcGIS Pro WFS (no transactions): https://doc.esri.com/en/arcgis-pro/latest/help/data/services/use-wfs-services.html
- [E2] Esri, ArcGIS Pro OGC API (Part 1, not editable): https://doc.esri.com/en/arcgis-pro/latest/help/data/services/use-ogc-api-services.html
- [E3] Esri, PostgreSQL database data not editable via .sde: https://doc.esri.com/en/arcgis-pro/latest/help/data/geodatabases/manage-postgresql/overview-geodatabases-postgresql.html
- [E4] Esri, Databases and ArcGIS (GeoPackage editable): https://doc.esri.com/en/arcgis-pro/latest/help/data/databases/databases-and-arcgis.html
- [E10] Esri, Add WFS services (custom request parameters, server authentication): https://doc.esri.com/en/arcgis-pro/latest/help/data/services/add-wfs-services.html
- [E11] Esri, PostgreSQL requirements (PostGIS 3.5, not 3.5.3/3.6.0): https://enterprise.arcgis.com/en/system-requirements/12.0/windows/database-requirements-postgresql.htm
- [Q4] QGIS OAPIF editing requires POST: https://github.com/qgis/qgis/issues/65120
- [O1] OGC API Features Part 4 (draft): https://github.com/opengeospatial/ogcapi-features/tree/master/extensions/transactions
- [PG] PostgreSQL `set_config` / `current_setting`: https://www.postgresql.org/docs/current/functions-admin.html
