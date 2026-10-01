# Re-smoke #41/#46 — export + publish

Read-only live pass. No product commit. No pull request. `.env` was edited locally and is gitignored.

## 1. HEAD tested

`88ec7e236c25d01735d59f46caf12bb39a431101` (`88ec7e2`, PR #47, parent `10e7f9f` / #40).

Checkout: detached HEAD via `git fetch https://github.com/alslimany/laravel-gis.git 88ec7e236c25d01735d59f46caf12bb39a431101` then `git checkout --detach`. SSH `origin` returned `Permission denied (publickey)`. Working tree before checkout was `main` at `3e3099e` with only untracked `artifacts/`. Nothing was stashed. Nothing was discarded.

`laravel-app` was restarted after checkout so PHP-FPM loaded this SHA. Compose still bind-mounts `./` into the container.

## 2. GEOSERVER_URL

| | Value |
| --- | --- |
| Before | `http://geoserver:8081/geoserver` |
| After | `http://geoserver:8080/geoserver` |

Changed the running `.env` only (not committed). `php artisan config:show geoserver.url` inside `laravel-app` printed the internal port 8080 URL. `GET http://geoserver:8080/geoserver/rest/about/version.json` from `laravel-app` returned GeoServer 2.21.0. No config cache file was present.

## 3. Export GeoJSON — Pass

Actor: existing editor `smoke-editor-issue41@example.com` (user 7, organization 7). Target: import 14 / layer 12 `smoke`, table `import_org7_smoke_d19ec5a6`, geometry column `geom` (POINT).

| Path | Request | Result |
| --- | --- | --- |
| Map (`downloadExport` Accept) | `GET /export/layer/12/geojson` `Accept: application/json, application/geo+json, text/csv, */*` | **200** `Content-Type: application/geo+json` `Content-Disposition: attachment; filename="smoke_2026-09-26.geojson"` |
| Layer page link | same URL, browser `Accept: text/html,...` | **200**, same attachment |

Body (167 bytes): FeatureCollection, one Point `[13.191338, 32.887209]`, property `name=Smoke Point`. No `SQLSTATE` / `42703`.

## 4. Export CSV — Pass

| Path | Request | Result |
| --- | --- | --- |
| Map | `GET /export/layer/12/csv` with the map Accept header | **200** `Content-Type: text/csv` `filename="smoke_2026-09-26.csv"` |
| Layer page | same URL, browser Accept | **200**, same attachment |

Body: `id,name,wkt` and `1,"Smoke Point","POINT(13.191338 32.887209)"`. No `SQLSTATE` / `42703`.

## 5. Publish — Pass

`POST /layers/12/publish` → **302** `Location: /layers/12`. Follow-up `GET /layers/12` flash: `Layer published to GeoServer successfully.` Layer props: `published=true`, `geoserver_workspace=org_7`.

DB: `published=t`, `published_at=2026-09-26 18:52:23`, `geoserver_layer_name=import_org7_smoke_d19ec5a6`.

GeoServer REST from `laravel-app`: `GET /rest/workspaces/org_7/datastores/postgis_store/featuretypes.json` → **200**, feature type `import_org7_smoke_d19ec5a6`.

## 6. PHPUnit

`docker compose exec laravel-app php artisan test --filter='CoreLoopDemoTest|DataImportTest|LayerTest|ExportTest'`

**50 passed** (270 assertions, 2.83s). Includes `geojson and csv export after geojson import with geom column`. `phpunit.xml` uses sqlite `:memory:`.

## 7. Verdict seed

| Item | Verdict |
| --- | --- |
| #46 AC (export) | **PASS** |
| #41 export item (GeoJSON + CSV) | **PASS** |
| Publish (previously blocked) | **PASS** |
