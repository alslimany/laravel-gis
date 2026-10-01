# DEMO.md smoke — issue #41

Read-only live pass against the running `gis-app` Docker stack. No application code was changed. No pull request.

## 1. Environment

| Service | Container | State | Host port |
| --- | --- | --- | --- |
| Nginx / app | `nginx-webserver` | Up | **8080** → 80, 8443 → 443 |
| PHP-FPM | `laravel-app` | Up | 9000 (internal) |
| Queue | `laravel-queue` | Up | `php artisan queue:work database --sleep=1 --tries=1 --timeout=1800` |
| PostGIS 13 | `postgis-db` | Up | **5432** |
| Redis | `redis-cache` | Up | **6379** |
| GeoServer 2.21 | `geoserver` | Up | **8081** → container 8080 |

Compose project `gis-app`, working dir `/Users/abdullah/Herd/gis-app`. Herd has no linked sites. Port **80 is closed**. `GET /health` → `{"status":"healthy"}`.

`APP_URL=http://localhost` (no port). Requests to `http://127.0.0.1:8080` redirect on that same host, so the browser demo used **http://127.0.0.1:8080**. README still says the app is `http://localhost` and GeoServer is `http://localhost:8080/geoserver`. Makefile `install` says the app is `:8080` and GeoServer is `http://localhost:8080/geoserver`. The live GeoServer admin UI is **http://127.0.0.1:8081/geoserver**.

`QUEUE_CONNECTION=database`. Redis is up and is not the queue.

An older compose project `laravel-gis` under `/Users/abdullah/Herd/laravel-gis` is exited and was not used.

## 2. HEAD

`3e3099e00f625b274bb41fa1da6e3c530f361403` (`3e3099e`, PR #38) on `main`, tracking `origin` `git@github.com:alslimany/laravel-gis.git`. Working tree was clean before this note.

## 3. DEMO step matrix

Actor: local editor `smoke-editor-issue41@example.com` (user 7, role editor, organization 7 “Smoke Clean Org”). The seeded `test@example.com` account logs in (HTTP 204) but is an **admin** on organization 1, which already has 4 imports, 4 layers, and 1 map, so it does not satisfy step 1. The smoke user uses the stock local factory password. Organization 7 had no imports, layers, or maps before the upload.

| Step | Result | Evidence |
| --- | --- | --- |
| 1. Sign in as editor on a clean org | **Pass** | Chrome login landed on `http://127.0.0.1:8080/dashboard`. Import page chrome shows Smoke Editor. |
| 2. Import a small GeoJSON | **Pass** | `POST /imports` with a 161-byte FeatureCollection (one point) → `302 /imports/14`. Queue finished immediately: `status=completed`, `progress=100`, `feature_count=1`, table `import_org7_smoke_d19ec5a6`, geometry `Point`, column `geom`. |
| 3. Stay until Completed, then Open layer (draft) | **Pass** | Import page text: “Completed”, “Open layer”. Layer 12 page: “Draft”, “Draft, not on GeoServer”, `published=false`. |
| 4. Publish | **Blocked** | `POST /layers/12/publish` stayed on `/layers/12` with flash `Failed to publish layer: Failed to create workspace: org_7`. Row stayed `published=false`. See environment blockers. The documented failure branch (draft + error) is what the page did. |
| 5. Edit fill/stroke and Save style | **Pass** | `POST /layers/12/style` → 200. Stored `fill_color=#0f766e`, `stroke_color=#134e4a`, `stroke_width=2`, `fill_opacity=0.6`. Layer page shows `simple, #0f766e`. GeoServer style push did not run because the layer is still a draft. |
| 6. Open in map and save | **Pass** | `/maps/builder?layer=12` seeds an MVT layer. `POST /api/maps` created map 7 “Smoke Map”. On `/maps/builder/7`, OSM tiles and `/api/layers/12/tiles/...mvt` returned **200**. |
| 7. Maps → Add layer → From GeoServer | **Blocked** | `GET /layers?published=1` → `{"data":[]}`. Nothing is published, so that list is empty. |
| 8a. Analysis buffer | **Pass** | `POST /api/analysis/buffer` 50 m around the point → 200, `success=true`, polygon WKT. |
| 8b. Spatial query | **Pass** | `intersects` → 200, `count=1`, feature “Smoke Point”. |
| 8c. Attribute query | **Pass** | `name = Smoke Point` → 200, `count=1`. |
| 8d. Export GeoJSON | **Fail** | Map-workspace `Accept` header: **500** `column "geometry" does not exist` on `ST_AsGeoJSON(geometry)` against `import_org7_smoke_d19ec5a6` (column is `geom`). A normal browser GET redirects to `/layers/12` with flash `Export failed: ... column "geometry" does not exist`. |
| 8e. Export CSV | **Fail** | Same request shape: **500** `column "geometry" does not exist` on `ST_AsText(geometry)`. |
| 8f. Export Map Config | **Pass** | `GET /export/map/7/config` → 200, `Content-Disposition: attachment; filename="Smoke_Map_config_2026-09-26.json"`. |
| 8g. Export as Image | **Pass** | Chrome on `/maps/builder/7`, Analysis → Export as Image. No alert. Downloaded `map_*.png`, PNG 1400×900 RGBA, 1,097,584 bytes, not a flat fill. |
| 9. Share public, signed-out vector tiles | **Pass** | Map 7 `is_public=true`, share token set. Share page stays at `/maps/7/share`. Signed-out `GET /maps/shared/{token}` → 200, no user, basemap `osm`, only layer 12. Chrome loaded OSM tiles **200** and `/maps/shared/{token}/tiles/12/...mvt` **200**. Another organization’s layer 8 on that token → **404**. Signed-out `/api/layers/12/tiles/...` → **401**. |

Local rows left in place from this run: user 7, organization 7, import 14, layer 12, map 7 (public).

## 4. P0 demo-breakers

**GeoJSON and CSV export query a `geometry` column that imports do not create.**

Repro on this stack:

1. Sign in as an editor and import a small GeoJSON.
2. Wait until the import is Completed and open the layer. The PostGIS table column is `geom` (confirmed on `import_org7_smoke_d19ec5a6`).
3. On the map, Export as GeoJSON or Export as CSV (`GET /export/layer/{id}/geojson` and `/csv` with `Accept: application/json, application/geo+json, text/csv, */*`).

Result: HTTP 500, `SQLSTATE[42703]`, `column "geometry" does not exist`. The layer-page links (`<a href="/export/layer/{id}/geojson">`) redirect back to the layer with `Export failed:` plus that SQL error. Buffer, spatial query, and attribute query succeed on the same layer because they resolve the column through `GeometryColumnHelper`. `ExportController::exportGeoJSON` and `exportCSV` hardcode `geometry`.

## 5. Environment blockers

No `CHANGE_ME` placeholders. The stack was already up; `.env` was not rewritten.

**Publish cannot reach GeoServer with the current `GEOSERVER_URL`.**

- Configured: `http://geoserver:8081/geoserver` → from `laravel-app`, connection refused (port 8081 is the host mapping, not the container port).
- Working internal URL, same admin user already in `.env`: `http://geoserver:8080/geoserver/rest/about/version.json` → **200**.
- `.env.example` already uses `GEOSERVER_URL=http://geoserver:8080/geoserver` and `GEOSERVER_PUBLIC_URL=http://127.0.0.1:8081/geoserver`. This `.env` has no `GEOSERVER_PUBLIC_URL`; the config default matches the host port.

Until that URL is the internal port 8080, Publish stays on the draft error branch and “From GeoServer” stays empty.

## 6. Verdict seed

**FAIL**

Import, queue completion, draft layer, style, map save, analysis, map-config download, PNG export, and signed-out MVT all passed. Publish success is blocked by this machine’s GeoServer URL. GeoJSON and CSV export fail on a real imported table.

## 7. Local command sequence that replaces CI

This Herd checkout is the demo host. There is no separate staging URL. Expected ports match the running stack: nginx **8080**, GeoServer **8081**, PostGIS **5432**, Redis **6379**, and the `queue` service.

Web Dev’s local substitute for the CI gate, run inside the app container so it uses this checkout:

```bash
docker compose exec laravel-app php artisan test --filter='CoreLoopDemoTest|DataImportTest|LayerTest|ExportTest'
```

Ran on 2026-09-26 against HEAD `3e3099e`: **48 passed** (234 assertions, 2.49s). `phpunit.xml` forces sqlite `:memory:`, so this does not touch the PostGIS volume or GeoServer.

That green run does not cover the live P0. `CoreLoopDemoTest::test_browser_layer_export_returns_to_the_layer_when_the_table_cannot_be_read` only asserts a redirect/500 when the table is missing. It never imports a GeoJSON into PostGIS or expects a `geom` column download. The full Feature suite is an optional wider gate (`docker compose exec laravel-app php artisan test --testsuite=Feature`) and was not run. Do not copy `.env.example` over this machine’s `.env`.
