# Early Warning plugin: pilot basins (#59, Tripoli wadis)

First-party plugin package (ADR `docs/adr/0001-plugin-architecture.md`). This slice adds the
global `ew_basins` table, an offline DEM → basin pipeline, and a command that links imported
basins to the table. The plugin registry/`plugin:install` arrive in #54; until then run the
migration by path.

## Decisions
- `ew_basins` is **global** (no `organization_id`); orgs subscribe to areas later (ADR D12).
- Geometry lives in a normal `layers` row created by the existing Data Import flow
  (`metadata.plugin = "early-warning"`); `ew_basins` links via `layer_id` + `feature_id`.
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

## 3. Delineate basins
```bash
python tools/delineate_basins.py           # -> data/out/tripoli_basins.geojson + SHA256SUMS
```
Choices (all in `tools/aoi.json`, edit and rerun):
- **AOI bbox** `[12.95, 32.45, 13.55, 32.95]` (Janzour → Tajoura, inland Jefara catchments) + 0.05° buffer.
- **CRS** UTM 33N (EPSG:32633) at 30 m for metric area/slope; output reprojected to EPSG:4326.
- **Conditioning** `BreachDepressionsLeastCost` (dist 100, fill) — breaching preserves urban channels better than filling.
- **Stream threshold** 1 km² contributing area (≈1,111 cells) — conservative for small, poorly defined urban catchments.
- **Outlets** manual pour points at coastal mouths / urban crossings, snapped (`JensonSnapPourPoints`, 300 m) to streams.
  **PROVISIONAL:** Wadi Al-Majenin, Wadi Al-Rabie, Wadi Al-Hira — coordinates approximate, need confirmation.
- **Attributes** `basin_code, name, name_ar, area_km2, mean/max_slope_deg, max_flow_acc_cells/km2,
  outlet_lon/lat, dem_source, dem_version, processed_at, stream_threshold_km2`. Polygons simplified at 15 m.

## 4. Import + link
1. Upload `data/out/tripoli_basins.geojson` through the normal Data Import UI (SRID 4326),
   set layer metadata `plugin = early-warning`, publish to GeoServer, check in Map Builder.
2. Run the migration (until #54's `plugin:install`):
   `php artisan migrate --path=packages/early-warning/database/migrations`
3. Link: `php artisan early-warning:link-basins packages/early-warning/data/out/tripoli_basins.geojson --layer=<layers.id>`
   (idempotent upsert by `basin_code`; `feature_id` resolved from the layer's import table).
   The command is registered by `EarlyWarningServiceProvider`, which gets loaded once #54 wires the composer path repo.

## Tests
`php vendor/bin/phpunit packages/early-warning/tests` (uses a tiny fixture GeoJSON).

## Data attribution
Copernicus DEM GLO-30 © DLR e.V. 2010-2014 and © Airbus Defence and Space GmbH 2014-2018,
provided under COPERNICUS by the European Union and ESA; all rights reserved. Accessed via the
AWS Open Data bucket `copernicus-dem-30m`.
