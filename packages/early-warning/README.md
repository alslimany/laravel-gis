# Early Warning plugin: pilot basins (#59, Tripoli wadis)

First-party plugin package (ADR `docs/adr/0001-plugin-architecture.md`). This slice adds the
global `ew_basins` table, an offline DEM → basin pipeline, and a command that links imported
basins to the table. The plugin registry/`plugin:install` arrive in #54; until then run the
migration by path.

## Decisions
- `ew_basins` is **global** (no `organization_id`); orgs subscribe to areas later (ADR D12).
- Geometry is stored **in `ew_basins.geom`**: `geometry(MultiPolygon, 4326)`, GIST index,
  `CHECK (ST_IsValid(geom))`, loaded with bound
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

## 2. Download DEM
```bash
python tools/download_dem.py               # -> data/dem/*.tif (2 GLO-30 tiles, N32 E012/E013)
```
Each tile is checked against the SHA-256 pinned in `tools/aoi.json` (`dem.sha256`). A missing pin
or a mismatch stops the script (fail closed).

## 3. Delineate basins
```bash
python tools/delineate_basins.py           # -> data/out/tripoli_basins.geojson + SHA256SUMS
```
Choices (all in `tools/aoi.json`, edit and rerun):
- **AOI bbox** `[12.95, 32.45, 13.55, 32.95]` (Janzour → Tajoura, inland Jefara catchments) + 0.05° buffer.
- **CRS** UTM 33N (EPSG:32633) at 30 m for metric area/slope; output reprojected to EPSG:4326.
- **Conditioning** `BreachDepressionsLeastCost` (dist 100, fill) — breaching preserves urban channels better than filling.
- **Stream threshold** 1 km² contributing area (≈1,111 cells) — conservative for small, poorly defined urban catchments.
- **Outlet join** snapped outlets are joined to watersheds by raster `VALUE`, never by row order;
  every outlet must produce exactly one non-empty, valid polygon (`make_valid` + asserts).
- **Outlets** manual pour points at coastal mouths / urban crossings, snapped (`JensonSnapPourPoints`, 300 m) to streams.
  **PROVISIONAL:** Wadi Al-Majenin, Wadi Al-Rabie, Wadi Al-Hira — coordinates approximate, need confirmation.
- **Attributes** `basin_code, name, name_ar, area_km2, mean/max_slope_deg, max_flow_acc_cells/km2,
  outlet_lon/lat, dem_source, dem_version, processed_at, stream_threshold_km2`. Polygons simplified at 15 m.

## 4. Migrate + load
1. Run the migration after core, in its own batch (until #54's `plugin:install`):
   `php artisan migrate --path=packages/early-warning/database/migrations --realpath`
   Rollback: `php artisan migrate:rollback --path=packages/early-warning/database/migrations --realpath`.
2. Load: `php artisan early-warning:link-basins packages/early-warning/data/out/tripoli_basins.geojson`
   (idempotent upsert by `basin_code`, attributes + geometry). Every feature is schema-checked
   first (non-empty `basin_code`, Polygon/MultiPolygon geometry, numeric/string types); if any
   feature fails, nothing is written.
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
