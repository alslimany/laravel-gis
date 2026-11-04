# Runbook DG-0.6 — Upgrade to PostgreSQL 17 / PostGIS 3.5 / GeoServer 2.28.6

**Status:** Documentation only (DG-0.6).  
**Gate DG-0.7:** Do **not** apply this upgrade to Abdullah’s local DB or to production until the NCMCD demo is explicitly confirmed complete. Live `docker-compose*.yml` tags stay on the current EOL stack until that gate opens.

**Related:** ADR-0002 §8 (upgrade constraints), `GEOSERVER_INTEGRATION.md`, backlog DG-0.6 / gate DG-0.7.

---

## 0. Critical gate (DG-0.7)

| Rule | Detail |
|------|--------|
| No local apply | Do not retarget volumes, restore into, or `ALTER EXTENSION` on Abdullah’s working PostGIS volume while NCMCD demo data is still needed. |
| No production apply | Do not change production compose digests or cut over production volumes until after a successful throwaway dry-run **and** DG-0.7 clearance. |
| Docs + throwaway only | This runbook may be rehearsed on **new / disposable** Docker volumes and project copies only. |
| Compose tags | Do **not** change live image tags in `docker-compose.yml` / `docker-compose.production.yml` yet. Use the **commented placeholders** in §9 when preparing a PR after the gate. |

---

## 1. Current vs target

| Component | Current (repo) | Target |
|-----------|----------------|--------|
| PostGIS image | `postgis/postgis:13-3.1` (local + production compose) | `postgis/postgis:17-3.5` **pinned by digest** |
| PostGIS version inside tag | 3.1.x on PG 13 | **3.5.2** (extension `default_version`) — **avoid 3.5.3 and 3.6.0** ([Esri E11](https://enterprise.arcgis.com/en/system-requirements/12.0/windows/database-requirements-postgresql.htm)) |
| GeoServer image | `kartoza/geoserver:2.21.0` | Official `docker.osgeo.org/geoserver:2.28.6` **pinned by digest** |
| GeoServer data dir (in container) | `/opt/geoserver/data_dir` (Kartoza) | `/opt/geoserver_data/` (OSGeo official) |
| Imagery bind | `./storage/app/imagery` → `/opt/imagery` (local compose) | Keep host path; remount into the new GeoServer service |
| Named volumes | `postgis-data`, `geoserver-data` | **Leave old volumes untouched**; cut over to **new** volume names |

### 1.1 Image pins (digest)

Prefer the **multi-arch index / manifest list** digest in Compose (`image: name@sha256:…`). Re-verify before every apply — tags can be re-pushed.

| Pin | Reference |
|-----|-----------|
| PostGIS | `postgis/postgis:17-3.5@sha256:01a6a70e41e6c4467c8f55f6063555ed72db2d6662cd0d571040d42eadaeb6f6` |
| PostGIS amd64 platform (info) | `sha256:8dfee83d8bd4c2873dc4a233c13ba2799a44f2edb16a0552d58715917fac32ba` |
| GeoServer | `docker.osgeo.org/geoserver:2.28.6@sha256:d38f2bcae4e64516d49f7d8006384fc46708877a3ec0b386e7fa12fc525bf76c` |
| GeoServer linux/amd64 | `sha256:9f3b93ce6bfeff0f560a3867eeb76d5b5b46afacde8712ad4a793c27fd773a85` |
| GeoServer linux/arm64 | `sha256:052a5f0c8e85b6ee067496d80f663fb5fb2de534118fd6128e572a32d285d565` |

**PostGIS note:** Hub currently publishes `17-3.5` for **linux/amd64** (no arm64 image). On Apple Silicon, Compose will emulate amd64 unless you build a custom arm64 image — plan CPU/time accordingly for dry-runs. Confirmed in a local inspect: extension default is **3.5.2** (not 3.5.3). Do **not** use `postgis/postgis:17-3.6` (seen locally as a different digest).

**GeoServer note:** Kartoza Hub tags stop short of **2.28.6** (2.28.5 is present). ADR-0002 requires **2.28.6**, so pin the **OSGeo** image. Switching vendors changes the data-directory path and several env vars (§5.3).

### 1.2 How to re-fetch digests

```bash
# PostGIS (Docker Hub)
curl -sS "https://hub.docker.com/v2/repositories/postgis/postgis/tags/17-3.5" \
  | python3 -c 'import sys,json; r=json.load(sys.stdin); print(r["digest"]);
[print(i["architecture"], i["digest"]) for i in r.get("images",[]) if i.get("architecture") in ("amd64","arm64")]'

# Confirm PostGIS patch (throwaway container — do not attach live volumes)
docker run --rm --entrypoint bash postgis/postgis:17-3.5@sha256:01a6a70e41e6c4467c8f55f6063555ed72db2d6662cd0d571040d42eadaeb6f6 \
  -c "grep default_version /usr/share/postgresql/17/extension/postgis.control"
# Expect: default_version = '3.5.2'

# GeoServer (OSGeo registry)
curl -sS -D - -o /tmp/gs.manifest \
  -H "Accept: application/vnd.oci.image.index.v1+json" \
  "https://repo.osgeo.org/v2/geoserver/manifests/2.28.6" | grep -i docker-content-digest
# Or: docker buildx imagetools inspect docker.osgeo.org/geoserver:2.28.6
```

If a tool cannot reach the registry, leave `TODO_DIGEST` in the PR and block merge until digests are filled.

### 1.3 GeoServer release window (follow-up)

Line **2.28.x** is in **security-only** maintenance; **3.0.x** is the active stable branch ([GeoServer support windows](https://endoflife.date/geoserver)). After DG-0.6 lands, schedule a separate story for GeoServer **3.0** (Tomcat 11 base image, extension matrix, AuthKey / GeoFence re-test). Do not fold 3.0 into this cutover.

---

## 2. Preconditions checklist

- [ ] **DG-0.7 clearance** recorded (NCMCD demo confirmed) — otherwise stop after backup rehearsal on throwaway only.
- [ ] Disk space ≥ 2× (`pg_dump` + data-dir tarball + imagery copy).
- [ ] Maintenance window booked; rollback owner identified (§7).
- [ ] `.env` has real `DB_*` and `GEOSERVER_ADMIN_*` (no demo fallbacks — see compose comments and `GEOSERVER_INTEGRATION.md`).
- [ ] Record current row-count / extent baselines (§6) **before** any cutover.
- [ ] Confirm no long-running imports (`ProcessImageryJob`, queue workers) mid-flight.

---

## 3. Backup (before any cutover)

Work from the **compose project root**. Use a dated directory, e.g. `./docker/postgres/backups/dg06-$(date +%Y%m%dT%H%M%S)/`.

### 3.1 PostgreSQL — custom format + globals

```bash
BACKUP_DIR="./docker/postgres/backups/dg06-$(date +%Y%m%dT%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Logical dump of the app DB (custom format — preferred for pg_restore)
docker compose exec -T postgis \
  pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc -f /tmp/lumina.dump
docker compose cp postgis:/tmp/lumina.dump "$BACKUP_DIR/lumina.dump"

# Roles, tablespaces, etc.
docker compose exec -T postgis \
  pg_dumpall -U "$DB_USERNAME" --globals-only -f /tmp/globals.sql
docker compose cp postgis:/tmp/globals.sql "$BACKUP_DIR/globals.sql"
```

Production variant: same commands against `docker compose -f docker-compose.production.yml`, or the `db-backup` sidecar host path `./docker/postgres/backups` — still produce **`-Fc`** and **`--globals-only`** for this upgrade (the stock `backup.sh` today uses plain SQL gzip; do not rely on it alone for DG-0.6).

### 3.2 GeoServer data directory tarball

```bash
# Named volume → tarball (Kartoza path inside the running container)
docker compose exec -T geoserver \
  tar -C /opt/geoserver/data_dir -czf /tmp/geoserver-data.tgz .
docker compose cp geoserver:/tmp/geoserver-data.tgz "$BACKUP_DIR/geoserver-data.tgz"
```

### 3.3 Imagery tree

```bash
tar -C ./storage/app -czf "$BACKUP_DIR/imagery.tgz" imagery
```

### 3.4 Checksums

```bash
( cd "$BACKUP_DIR" && shasum -a 256 lumina.dump globals.sql geoserver-data.tgz imagery.tgz > SHA256SUMS )
shasum -a 256 -c "$BACKUP_DIR/SHA256SUMS"
```

Store `$BACKUP_DIR` off-box if this is a production rehearsal. **Do not delete** the old Docker volumes after backup — they are the fast rollback path (§7).

---

## 4. Upgrade strategy (new volumes — never in-place major PG upgrade)

PostgreSQL **13 → 17** is **not** an in-place data-directory bump. Procedure:

1. Keep existing `postgis-data` / `geoserver-data` volumes **read-only / untouched**.
2. Create **new** volumes (example names below).
3. Start a **new** PostGIS 17 container on the empty volume; restore dump; run `ALTER EXTENSION postgis UPDATE`.
4. Start GeoServer 2.28.6 on a **copy** of the data directory (new volume), not the live volume.
5. Point the app compose file at the new services only after verification.
6. Retain old volumes ≥ 7 days (or until DG-0.7 stakeholders sign off).

Suggested new volume names (throwaway or post-gate):

- `postgis-data-pg17`
- `geoserver-data-228`

---

## 5. Procedure (throwaway dry-run first; live only after DG-0.7)

### 5.1 Optional dry-run project

Copy compose into a disposable override, e.g. `docker-compose.dg06-dryrun.yml`, with:

- Different `container_name` / project name (`docker compose -p lumina-dg06 …`).
- **New** volume names only.
- Published ports shifted (e.g. `5433:5432`, `8082:8080`) so the live stack is undisturbed.
- Image lines using the digests from §1.1.

**Never** attach the dry-run PostGIS service to the existing `postgis-data` volume.

### 5.2 PostGIS — restore onto new volume

```bash
# 1. Start empty PG17+PostGIS 3.5.2 (new volume)
docker compose -p lumina-dg06 -f docker-compose.dg06-dryrun.yml up -d postgis
# wait for pg_isready

# 2. Restore globals (roles) then database
docker compose -p lumina-dg06 cp "$BACKUP_DIR/globals.sql" postgis:/tmp/globals.sql
docker compose -p lumina-dg06 exec -T postgis \
  psql -U "$DB_USERNAME" -d postgres -f /tmp/globals.sql

docker compose -p lumina-dg06 cp "$BACKUP_DIR/lumina.dump" postgis:/tmp/lumina.dump
docker compose -p lumina-dg06 exec -T postgis \
  pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --no-owner --role="$DB_USERNAME" \
  /tmp/lumina.dump
# Review errors: ignore "already exists" for extension stubs if CREATE EXTENSION raced; fail on data errors.

# 3. Update PostGIS extension to the image’s 3.5.2 scripts
docker compose -p lumina-dg06 exec -T postgis \
  psql -U "$DB_USERNAME" -d "$DB_DATABASE" \
  -c "ALTER EXTENSION postgis UPDATE;" \
  -c "SELECT postgis_full_version();"
# Expect POSTGIS="3.5.2 ..." on PostgreSQL 17.x

# Optional topology / raster if present:
# ALTER EXTENSION postgis_topology UPDATE;
# ALTER EXTENSION postgis_raster UPDATE;
```

### 5.3 GeoServer — copy of data dir on 2.28.6

1. Create a host staging directory from the tarball:

   ```bash
   STAGE=$(mktemp -d)
   tar -C "$STAGE" -xzf "$BACKUP_DIR/geoserver-data.tgz"
   ```

2. Populate a **new** named volume (or bind mount) and mount it at **`/opt/geoserver_data/`** (OSGeo path — not Kartoza’s `/opt/geoserver/data_dir`).

3. Example service sketch (dry-run / post-gate only — **commented placeholder for live compose** in §9):

   ```yaml
   geoserver:
     # LIVE TAGS UNCHANGED UNTIL DG-0.7 — example target only:
     # image: docker.osgeo.org/geoserver:2.28.6@sha256:d38f2bcae4e64516d49f7d8006384fc46708877a3ec0b386e7fa12fc525bf76c
     environment:
       GEOSERVER_ADMIN_USER: "${GEOSERVER_ADMIN_USER}"
       GEOSERVER_ADMIN_PASSWORD: "${GEOSERVER_ADMIN_PASSWORD}"
       SKIP_DEMO_DATA: "true"
       EXTRA_JAVA_OPTS: "-Xms2G -Xmx4G"
       CORS_ENABLED: "true"
       CORS_ALLOWED_ORIGINS: "*"   # production: ${APP_URL}
       INSTALL_EXTENSIONS: "true"
       STABLE_EXTENSIONS: "authkey"  # add others as required by ADR-0002
       # GEOSERVER_TRUSTED_PROXIES: "<docker bridge CIDR>"  # 2.28.6+ behind proxy
     volumes:
       - geoserver-data-228:/opt/geoserver_data/
       - ./storage/app/imagery:/opt/imagery:ro
   ```

4. After first boot, fix JDBC datastore host/port if the dry-run PostGIS hostname/port differs; re-save workspaces via REST or the UI.
5. App config (`config/geoserver.php` / `.env`): keep `GEOSERVER_URL` / `GEOSERVER_PUBLIC_URL` aligned with the new port mapping.

### 5.4 Application cutover (only after DG-0.7 + verification)

1. Stop queue/scheduler writers briefly.
2. Final incremental dump from old PG if writes continued (or accept freeze from §3).
3. Repoint compose volumes + digests; `docker compose up -d`.
4. Run `php artisan migrate --force` if any pending migrations (should be no-ops if dump is current).
5. `php artisan config:clear` / restart `laravel-app`, `queue`, `scheduler`.
6. Execute §6 verification before opening traffic.

---

## 6. Verification checklist

Record actual values in the change ticket.

### 6.1 Database

- [ ] `SELECT version();` → PostgreSQL **17.x**
- [ ] `SELECT postgis_full_version();` → **3.5.2** (not 3.5.3 / 3.6.0)
- [ ] Row counts match pre-upgrade baselines for core tables at minimum:

  ```sql
  SELECT 'users' AS t, COUNT(*) FROM users
  UNION ALL SELECT 'layers', COUNT(*) FROM layers
  UNION ALL SELECT 'organizations', COUNT(*) FROM organizations
  -- add feature tables / plugin tables used in demos
  ```

- [ ] Spatial sanity:

  ```sql
  -- Adjust table/column to a known published layer
  SELECT ST_Extent(geom) FROM <known_layer_table>;
  ```

  Extent matches pre-upgrade notes (same SRID / comparable bounds).

- [ ] `php artisan migrate:status` — no unexpected pending migrations.
- [ ] `php artisan test` — green on the verification host (or agreed subset + note failures).

### 6.2 GeoServer OGC

Replace host/workspace/layer with environment values (`GEOSERVER_PUBLIC_URL`, org workspace).

- [ ] GetCapabilities (WMS):  
  `curl -sf "$GEOSERVER_PUBLIC_URL/wms?service=WMS&request=GetCapabilities" | head`
- [ ] GetMap returns image bytes (HTTP 200, `Content-Type` image/*).
- [ ] GetFeature (WFS) returns GML/GeoJSON for a known layer.
- [ ] REST login with `GEOSERVER_ADMIN_*` succeeds (`php artisan geoserver:test` / `TestGeoServerConnection` if available).
- [ ] Imagery / COG layers that reference `/opt/imagery` still render.

### 6.3 Product demos

- [ ] **Disaster** demo paths smoke-tested (activations, incidents, map layers per `packages/disaster/DEMO_CONTENT.md`).
- [ ] **Early-warning** risk/alert UI for the demo org.
- [ ] **NCMCD** exercise org (`ncmcd-w26`) only after DG-0.7 — do not reseed or migrate that org’s DB as part of a dry-run against Abdullah’s live volume.

---

## 7. Rollback (< 30 minutes)

Rollback is **repoint + restart**, not a second dump restore, when old volumes were preserved.

1. Stop new containers: `docker compose stop postgis geoserver` (or project `-p lumina-dg06 down`).
2. Restore compose image tags to **current**:
   - `postgis/postgis:13-3.1`
   - `kartoza/geoserver:2.21.0`
3. Ensure volume names again reference the **original** `postgis-data` and `geoserver-data`.
4. `docker compose up -d postgis geoserver laravel-app nginx queue scheduler`
5. Smoke: `pg_isready`, GetCapabilities, one map load, `php artisan migrate:status`.

**Time budget:** stopping services ~1–2 min; compose edit/revert ~5 min; cold start GeoServer ~2–5 min; smoke ~5 min. Keep old volumes and the §3 `$BACKUP_DIR` until sign-off.

If the old volume was destroyed (should not happen), restore from `$BACKUP_DIR` onto a fresh 13-3.1 volume using `pg_restore` + GeoServer tarball at `/opt/geoserver/data_dir` — expect >30 minutes; treat as incident recovery.

---

## 8. Production notes

- Production compose (`docker-compose.production.yml`) mirrors the same current tags (`13-3.1`, `kartoza/geoserver:2.21.0`) and binds GeoServer to `127.0.0.1:8081`.
- `db-backup` service image is `postgres:13` — bump it to **17** in the same change set as PostGIS when the gate opens (dump client should match server major).
- CORS must stay `${APP_URL}` in production (not `*`).
- Prefer a staging dry-run with anonymized dump before production cutover.
- Same DG-0.7 spirit: no production digest flip until local/staging verification is signed.

---

## 9. Commented placeholders for live compose (do not activate yet)

Commented placeholders matching the blocks below are already beside the live image lines in `docker-compose.yml` and `docker-compose.production.yml` (DG-0.6 docs pass). **Do not uncomment** until after DG-0.7 + verification. Reference copy:

```yaml
  postgis:
    image: postgis/postgis:13-3.1
    # DG-0.6 target (activate only after DG-0.7 + verification):
    # image: postgis/postgis:17-3.5@sha256:01a6a70e41e6c4467c8f55f6063555ed72db2d6662cd0d571040d42eadaeb6f6
    # volumes:
    #   - postgis-data-pg17:/var/lib/postgresql/data

  geoserver:
    image: kartoza/geoserver:2.21.0
    # DG-0.6 target (activate only after DG-0.7 + verification):
    # image: docker.osgeo.org/geoserver:2.28.6@sha256:d38f2bcae4e64516d49f7d8006384fc46708877a3ec0b386e7fa12fc525bf76c
    # environment:
    #   SKIP_DEMO_DATA: "true"
    #   EXTRA_JAVA_OPTS: "-Xms2G -Xmx4G"
    #   INSTALL_EXTENSIONS: "true"
    #   STABLE_EXTENSIONS: "authkey"
    # volumes:
    #   - geoserver-data-228:/opt/geoserver_data/
    #   - ./storage/app/imagery:/opt/imagery
```

**Do not commit an uncommented digest swap in this story.**

---

## 10. Execution log (fill during real run)

| Step | Operator | Started | Finished | Result |
|------|----------|---------|----------|--------|
| DG-0.7 clearance | | | | |
| Backup + SHA256 | | | | |
| Throwaway dry-run | | | | |
| Local apply (blocked until DG-0.7) | | | | **BLOCKED** |
| Production apply | | | | |

---

## 11. References

- ADR-0002 §8 — upgrade constraints / Gate G1  
- `GEOSERVER_INTEGRATION.md` — REST/workspace/datastore behaviour  
- `docker-compose.yml` / `docker-compose.production.yml` — current tags  
- GeoServer Docker README — data dir `/opt/geoserver_data/`, extensions, CORS  
- Esri PostgreSQL requirements — PostGIS **3.5** excluding **3.5.3** / **3.6.0** [E11]  
- Backlog: **DG-0.6** (this runbook), **DG-0.7** (local DB apply gate)
