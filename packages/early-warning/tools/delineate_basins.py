#!/usr/bin/env python3
"""Delineate Tripoli wadi basins from Copernicus DEM with WhiteboxTools + GDAL/rasterio.

Pipeline (UTM 33N, metres):
  mosaic tiles -> clip to bbox+buffer -> reproject EPSG:32633 (30 m)
  BreachDepressionsLeastCost -> D8Pointer -> D8FlowAccumulation (cells)
  ExtractStreams (threshold = stream_threshold_km2) -> JensonSnapPourPoints
  Watershed (one basin per outlet) -> Slope (degrees)
  polygonise -> zonal stats -> simplify -> GeoJSON EPSG:4326

Output: data/out/tripoli_basins.geojson (+ checksums). Rasters stay in data/work (ignored).
Usage:  python tools/delineate_basins.py [--aoi tools/aoi.json]
"""
import argparse, json, pathlib, sys, hashlib, datetime as dt
import numpy as np
import rasterio
from rasterio.merge import merge
from rasterio.mask import mask
from rasterio.warp import calculate_default_transform, reproject, Resampling
from rasterio.features import shapes
import geopandas as gpd
from shapely.geometry import box, shape, Point
from rasterstats import zonal_stats
import whitebox

ROOT = pathlib.Path(__file__).resolve().parents[1]

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--aoi", default=str(ROOT / "tools" / "aoi.json"))
    a = ap.parse_args()
    aoi = json.load(open(a.aoi))
    dem_dir, work, out = ROOT / "data/dem", ROOT / "data/work", ROOT / "data/out"
    work.mkdir(parents=True, exist_ok=True); out.mkdir(parents=True, exist_ok=True)
    crs = aoi["target_crs"]

    # 1. mosaic + clip (WGS84)
    tiles = [rasterio.open(dem_dir / f"{t}.tif") for t in aoi["dem"]["tiles"]]
    mosaic, tr = merge(tiles)
    meta = tiles[0].meta.copy(); meta.update(height=mosaic.shape[1], width=mosaic.shape[2], transform=tr)
    b = aoi["bbox"]; buf = aoi["buffer_deg"]
    clip_geom = [box(b[0]-buf, b[1]-buf, b[2]+buf, b[3]+buf).__geo_interface__]
    with rasterio.MemoryFile() as mf:
        with mf.open(**meta) as ds:
            ds.write(mosaic)
            clipped, ctr = mask(ds, clip_geom, crop=True)
        meta.update(height=clipped.shape[1], width=clipped.shape[2], transform=ctr)
    # 2. reproject to UTM 30 m
    t2, w2, h2 = calculate_default_transform(meta["crs"], crs, meta["width"], meta["height"],
                                             *rasterio.transform.array_bounds(meta["height"], meta["width"], ctr), resolution=30)
    dem_utm = work / "dem_utm.tif"
    m2 = meta.copy(); m2.update(crs=crs, transform=t2, width=w2, height=h2, driver="GTiff", nodata=-9999, dtype="float32")
    with rasterio.open(dem_utm, "w", **m2) as dst:
        reproject(clipped.astype("float32"), rasterio.band(dst, 1), src_transform=ctr, src_crs=meta["crs"],
                  dst_transform=t2, dst_crs=crs, resampling=Resampling.bilinear, dst_nodata=-9999)

    # 3. hydrology
    wbt = whitebox.WhiteboxTools(); wbt.set_verbose_mode(False); wbt.set_working_dir(str(work))
    cell_km2 = (30 * 30) / 1e6
    thr_cells = int(aoi["stream_threshold_km2"] / cell_km2)
    wbt.breach_depressions_least_cost("dem_utm.tif", "dem_breached.tif", dist=100, fill=True)
    wbt.d8_pointer("dem_breached.tif", "d8.tif")
    wbt.d8_flow_accumulation("dem_breached.tif", "facc.tif", out_type="cells")
    wbt.extract_streams("facc.tif", "streams.tif", threshold=thr_cells)
    wbt.slope("dem_breached.tif", "slope.tif", units="degrees")

    # 4. pour points (provisional outlets) -> snap -> watershed
    pts = gpd.GeoDataFrame(aoi["outlets"], geometry=[Point(o["lon"], o["lat"]) for o in aoi["outlets"]], crs="EPSG:4326").to_crs(crs)
    pts["VALUE"] = range(1, len(pts) + 1)
    pts.to_file(work / "outlets.shp")
    wbt.jenson_snap_pour_points("outlets.shp", "streams.tif", "outlets_snapped.shp", snap_dist=aoi["snap_distance_m"])
    wbt.watershed("d8.tif", "outlets_snapped.shp", "basins.tif")

    # 5. polygonise + stats
    with rasterio.open(work / "basins.tif") as src:
        arr = src.read(1); polys = [(shape(g), int(v)) for g, v in shapes(arr.astype("int32"), mask=arr > 0, transform=src.transform)]
    g = gpd.GeoDataFrame([{"VALUE": v} for _, v in polys], geometry=[p for p, _ in polys], crs=crs).dissolve(by="VALUE").reset_index()
    g["geometry"] = g.geometry.make_valid()
    assert len(g) > 0, "watershed produced no basins (check outlets / snap distance)"
    assert not g.geometry.is_empty.any(), "empty basin polygon after polygonise"
    g = g.merge(pts.drop(columns="geometry"), on="VALUE", how="inner", validate="one_to_one")
    missing = set(pts["VALUE"]) - set(g["VALUE"])
    assert not missing, f"no watershed for outlet VALUE(s) {sorted(missing)}"
    snapped = gpd.read_file(work / "outlets_snapped.shp").to_crs("EPSG:4326")
    assert "VALUE" in snapped.columns, "snapped outlets lost the VALUE attribute"
    snap_xy = {int(v): (round(p.x, 6), round(p.y, 6)) for v, p in zip(snapped["VALUE"], snapped.geometry)}
    sl = zonal_stats(g, str(work / "slope.tif"), stats=["mean", "max"])
    fa = zonal_stats(g, str(work / "facc.tif"), stats=["max"])
    now = dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")
    g["area_km2"] = (g.geometry.area / 1e6).round(4)
    g["mean_slope_deg"] = [round(s["mean"] or 0, 3) for s in sl]
    g["max_slope_deg"] = [round(s["max"] or 0, 3) for s in sl]
    g["max_flow_acc_cells"] = [int(f["max"] or 0) for f in fa]
    g["max_flow_acc_km2"] = (g["max_flow_acc_cells"] * cell_km2).round(4)
    # join snapped outlets by raster VALUE (watershed id == pour-point VALUE), never by row position
    g["outlet_lon"] = [snap_xy[int(v)][0] for v in g["VALUE"]]
    g["outlet_lat"] = [snap_xy[int(v)][1] for v in g["VALUE"]]
    g["dem_source"] = aoi["dem"]["source"]; g["dem_version"] = aoi["dem"]["version"]; g["processed_at"] = now
    g["stream_threshold_km2"] = aoi["stream_threshold_km2"]
    g["geometry"] = g.geometry.simplify(aoi["simplify_tolerance_m"], preserve_topology=True)
    g = g.drop(columns=["VALUE", "lon", "lat"]).to_crs("EPSG:4326")
    g["geometry"] = g.geometry.make_valid()
    assert not g.geometry.is_empty.any(), "empty basin polygon after simplify/reproject"
    assert g.geometry.is_valid.all(), "invalid basin polygon after make_valid"
    assert set(g.geom_type) <= {"Polygon", "MultiPolygon"}, f"unexpected geometry types {set(g.geom_type)}"

    dest = out / "tripoli_basins.geojson"
    g.to_file(dest, driver="GeoJSON")
    digest = hashlib.sha256(dest.read_bytes()).hexdigest()
    (out / "SHA256SUMS").write_text(f"{digest}  {dest.name}\n")
    print(f"wrote {dest} ({len(g)} basins) sha256={digest}")
    print(g[["basin_code", "name", "area_km2", "mean_slope_deg", "max_flow_acc_km2"]].to_string(index=False))
    return 0

if __name__ == "__main__":
    sys.exit(main())
