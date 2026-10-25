# Early Warning plugin (v0.9.1)

First-party plugin package (ADR `docs/adr/0001-plugin-architecture.md`).

**Shipped**
- **#59** Global `ew_basins` + offline DEM → basin pipeline (`tools/`) + `early-warning:link-basins`
- **#58** Phase 1 schema: `ew_forecasts` (global), `ew_subscriptions` / `ew_risk_assessments` /
  `ew_alert_rules` / `ew_alert_logs` (org-scoped), config, routes under `/x/early-warning`
- **#60** Open-Meteo hourly precipitation ingest (`FetchForecastsJob`, hourly schedule when installed)
- **#61** Risk calculator (`RiskCalculator` + `ComputeRiskJob`) → `ew_risk_assessments`;
  `ew_risk_latest` view; `early-warning:publish-risk-layer` publishes an org Layer + GeoServer
  SQL View (`ew_basin_risk`) with green/yellow/orange/red SLD. Wording is advisory only.
- **#62** Map builder panel: `GET /x/early-warning/warnings` + `EarlyWarningPanel` (basin list +
  forecast-hour slider). Shown when the plugin is enabled for the org (`initialMap.plugins`).
- **Phase 2 AlertRule** Org-editable thresholds (`/x/early-warning/alert-rules`), Tripoli-calibrated
  defaults on enable, `EvaluateAlertsJob` → `ew_alert_logs`,
  `early-warning:suggest-thresholds` from local forecast history.
- **Phase 2 channels** `DispatchAlertsJob` delivers pending logs via `log` / `email` / `telegram`
  (`EW_TELEGRAM_BOT_TOKEN` + org `notify_emails` / `telegram_chat_ids`).
- **Phase 2 IMERG/soil** `ew_observations` + NASA POWER bridge (`PRECTOTCORR` / `GWETROOT`) via
  `FetchObservationsJob` (every 6h). Risk uses observed soil saturation and strengthens antecedent
  when observation coverage is sufficient. GES DISC granules can replace this later.
- **Phase 3 exposure** `ew_exposure_summaries` from OSM Overpass (building count + road km per
  basin). `ComputeExposureJob` daily; shown on console overview and warnings API.
- **Phase 3 public / citizen reports** Hashed `ew_public_tokens`, `ew_citizen_reports` (photo +
  location), public page `/ew/public/{token}`, SMS channel (`EW_SMS_WEBHOOK_URL` or log-only).
- **Advisory wording** Central `AdvisoryCopy` + config; every console/public/API/alert surface
  states forecast-based decision support. Optional official authority via
  `EW_ADVISORY_AUTHORITY_NAME` / `EW_ADVISORY_AUTHORITY_URL`.
- **Core contracts (Phase 0)** Risk layer publish goes through `App\Contracts\LayerPublisher`
  (not raw GeoServer). Listens to Core `LayerUpdated` / `LayerDeleted` for EW-tagged layers.
  `ew_basins` remains a transitional global index (linked via `layer_id`); geometry ownership
  migrating fully onto Core layers is a follow-up.

Install / upgrade via `php artisan plugin:install|upgrade early-warning` (D15).

### Risk compute + publish
```bash
php artisan early-warning:compute-risk --sync
php artisan early-warning:publish-risk-layer --organization=1
# Layer only (no GeoServer): --no-geoserver
```
Schedule (when installed): fetch forecasts hourly; fetch observations every 6h; compute risk at :05;
evaluate alerts at :10; dispatch channels at :15; compute exposure daily at 02:30. Enabling the
plugin for an org seeds `ew_subscriptions` and default AlertRules for all global basins.

### Exposure (OSM)
```bash
php artisan early-warning:compute-exposure --sync
php artisan early-warning:compute-exposure --sync --basin=TRP-HIRA
```

### Public feed + citizen reports
Issue a token under **Citizen reports** in the console (shown once). Public URLs:
- `/ew/public/{token}` — alert page + report form
- `/ew/public/{token}/feed` — JSON
- `POST /ew/public/{token}/reports` — photo + lat/lon

SMS: set `notify_phones` on Alert rules channels; optional `EW_SMS_WEBHOOK_URL`.

### Observations (IMERG / soil bridge)
```bash
# Earthdata bearer for future GES DISC granule downloads (local .env only):
# EW_EARTHDATA_TOKEN=…   # https://urs.earthdata.nasa.gov/ → Generate Token
php artisan early-warning:fetch-observations --sync
# Disable: EW_OBSERVATIONS_ENABLED=false
```

### Alert rules + channels
```bash
php artisan early-warning:seed-alert-rules
php artisan early-warning:suggest-thresholds --days=30
php artisan early-warning:evaluate-alerts --sync
php artisan early-warning:dispatch-alerts --sync
```

## Decisions
- `ew_basins` is **global** (no `organization_id`); orgs subscribe to areas later (ADR D12).
- Geometry is stored **in `ew_basins.geom`**: `geometry(MultiPolygon, 4326)`, GIST index,
  `CHECK (geom IS NULL OR (ST_IsValid(geom) AND NOT ST_IsEmpty(geom)))`, loaded with bound
  `ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3))`.
- An imported org layer is an **optional view only**: `layer_id`/`feature_id` are nullable, with
  no foreign key and no cascade, so no tenant's layer can delete or own global basins.
- Extra pipeline properties go in the `props` JSON column.
- Non-PostgreSQL drivers (the SQLite test DB) get a nullable text `geom` holding GeoJSON.
- DEM and rasters are **downloaded, never committed** (`data/dem`, `data/work` are git-ignored).

## 1. Python environment (offline, one-time)
```bash
cd packages/early-warning
python3 -m venv tools/.venv && source tools/.venv/bin/activate
pip install -r tools/requirements.txt      # needs GDAL libs (brew install gdal)
```
Prerequisite: **WhiteboxTools v2.4.0** (the binary the pinned `whitebox` package downloads on first run). The version is
recorded in each feature as `wbt_version`; other versions may change the flow-accumulation results.

## 2. Download DEM
```bash
python tools/download_dem.py               # -> data/dem/*.tif (4 GLO-30 tiles, N31/N32 × E012/E013)
```
Each tile is checked against the SHA-256 pinned in `tools/aoi.json` (`dem.sha256`). A missing pin
or a mismatch stops the script (fail closed).

## 3. Delineate basins
```bash
python tools/delineate_basins.py           # -> data/out/tripoli_basins.geojson + SHA256SUMS
```
**Reproducible:** a rerun with the same `aoi.json` and DEM tiles gives byte-identical output and an identical
`SHA256SUMS` (features sorted by `basin_code`, coordinates rounded to 6 dp, `processed_at` taken from
`SOURCE_DATE_EPOCH` or `aoi.json` `processed_at`, never the wall clock). The WhiteboxTools binary version is
recorded per feature (`wbt_version`). `--reuse-work` skips the DEM/hydrology steps when `data/work` rasters exist
(dev only; do not use after changing bbox, tiles or threshold).
Choices (all in `tools/aoi.json`, edit and rerun):
- **AOI bbox** `[12.95, 31.85, 13.65, 32.95]` (Janzour → Tajoura, inland past the Gharyan escarpment so the full Wadi Al-Majenin catchment fits; it crosses 32.0°N) + 0.05° buffer; needs 4 GLO-30 tiles (N31/N32 × E012/E013). A basin touching the buffered AOI edge fails the run.
- **CRS** UTM 33N (EPSG:32633) at 30 m for metric area/slope; output reprojected to EPSG:4326.
- **Conditioning** `BreachDepressionsLeastCost` (dist 100, fill) — breaching preserves urban channels better than filling.
- **Stream threshold** 1 km² contributing area (≈1,111 cells) — conservative for small, poorly defined urban catchments.
- **Outlet join** snapped outlets are joined to watersheds by raster `VALUE`, never by row order;
  every outlet must produce exactly one non-empty, valid polygon (`make_valid` + asserts).
- **Outlets** manual pour points at coastal mouths / urban crossings, snapped to the highest flow-accumulation stream cell within 400 m (done in Python; WBT's snap tools left points off-cell and produced 1-cell basins). A basin smaller than the stream threshold fails the run.
  **CONFIRMED by Abdullah (2026-10-24):** Wadi Al-Majenin, Wadi Al-Rabie, Wadi Al-Hira (1 km² threshold, 400 m snap; at 300 m Majenin/Rabie outlets were 379/390 m from their channels). Optional per-outlet `min_area_km2` fails the run if a basin
  comes out smaller (Majenin ≥ 2000, Rabie ≥ 40, Hira ≥ 5). Rabie moved from
  13.3350, 32.8790 (a ~3.7 km² urban side drainage) to the input point 13.3639, 32.8774, which was Abdullah's own pick (2026-10-24) on the main
  Wadi Rabie channel. The real pour point is the snapped cell 13.3661, 32.8801 (363.4 m away), and the resulting basin is **53.28 km²**.
  The ~57 km² figure was only an estimate for the unsnapped channel. The old point is kept as `previous_lon/lat` in `aoi.json`. A candidate at 13.3395, 32.8971 was rejected (sea level). Interior holes from raster→polygon are filled; a basin with holes fails the run.
- **Attributes** `basin_code, name, name_ar, area_km2, mean/max_slope_deg, max_flow_acc_cells/km2,
  outlet_lon/lat (= snapped_lon/lat), outlet_input_lon/lat, snap_dist_m, snap_max_m, wbt_version, dem_source, dem_version, processed_at, stream_threshold_km2`. Polygons simplified at 15 m.

## 4. Migrate + load
1. Run the migration after core, in its own batch (until #54's `plugin:install`):
   `php artisan migrate --path=packages/early-warning/database/migrations --realpath`
   Rollback: `php artisan migrate:rollback --path=packages/early-warning/database/migrations --realpath`.
2. Load: `php artisan early-warning:link-basins packages/early-warning/data/out/tripoli_basins.geojson`
   (idempotent upsert by `basin_code`, attributes + geometry). Every feature is schema-checked
   first (non-empty `basin_code`, Polygon/MultiPolygon geometry, numeric/string types); if any
   feature fails, nothing is written. On PostgreSQL each geometry is also cleaned
   (`ST_MakeValid` + `ST_CollectionExtract(…,3)`) up front; if any comes out empty the whole
   run fails and nothing is written.
3. Optional org view: upload the same GeoJSON through the Data Import UI (SRID 4326), set layer
   metadata `plugin = early-warning`, then rerun step 2 with `--layer=<layers.id>`. The command
   refuses layers whose `metadata.plugin` is not `early-warning`; `feature_id` is resolved from the
   layer's import table (`basin_code` column), falling back to the GeoJSON feature id.
   The command is registered by `EarlyWarningServiceProvider`, which gets loaded once #54 wires the composer path repo.

## Tests
`php vendor/bin/phpunit --testsuite Plugins` (suite `packages/*/tests` in the root `phpunit.xml`;
namespace `Lumina\EarlyWarning\Tests`, autoloaded once via root `composer.json` autoload-dev).
The PostGIS geometry tests are skipped unless a disposable PostGIS DB is given:
`EW_PGSQL_TEST_URL=pgsql://postgres@127.0.0.1:54329/ew_test php vendor/bin/phpunit --testsuite Plugins`.

## Data licence and attribution
The DEM is the **Copernicus DEM GLO-30**, used under the ESA/European Union *Licence for the use of
the Copernicus DEM GLO-30* (free of charge, worldwide, commercial and non-commercial use allowed,
attribution required). Required notice:

Copernicus DEM GLO-30 © DLR e.V. 2010-2014 and © Airbus Defence and Space GmbH 2014-2018,
provided under COPERNICUS by the European Union and ESA; all rights reserved. Accessed via the
AWS Open Data bucket `copernicus-dem-30m`.

## Tests
`php vendor/bin/phpunit --testsuite Plugins` (SQLite). The PostGIS path (`PostgisGeometryTest`)
needs a **disposable** database whose name **must end in `_test`** (the test drops `ew_basins`
and `migrations`; any other name is refused):
```bash
EW_PGSQL_TEST_URL=pgsql://postgres@127.0.0.1:54329/ew_test php vendor/bin/phpunit --testsuite Plugins
```
Without `EW_PGSQL_TEST_URL` those tests are skipped, but they **fail** when `EW_REQUIRE_PGSQL=1` or
`CI` is set, so CI cannot pass without PostGIS.
