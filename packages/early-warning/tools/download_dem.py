#!/usr/bin/env python3
"""Download Copernicus DEM GLO-30 tiles for the AOI (not committed; see .gitignore).

Usage: python tools/download_dem.py [--aoi tools/aoi.json] [--out data/dem]
Source: Copernicus DEM GLO-30, public AWS Open Data bucket (no auth).
"""
import argparse, json, pathlib, sys, hashlib
import requests

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--aoi", default=str(pathlib.Path(__file__).with_name("aoi.json")))
    ap.add_argument("--out", default=str(pathlib.Path(__file__).parents[1] / "data" / "dem"))
    a = ap.parse_args()
    aoi = json.load(open(a.aoi))
    out = pathlib.Path(a.out); out.mkdir(parents=True, exist_ok=True)
    for tile in aoi["dem"]["tiles"]:
        dest = out / f"{tile}.tif"
        if dest.exists() and dest.stat().st_size > 0:
            print(f"exists {dest}"); continue
        url = f'{aoi["dem"]["base_url"]}/{tile}/{tile}.tif'
        print(f"GET {url}")
        with requests.get(url, stream=True, timeout=120) as r:
            r.raise_for_status()
            h = hashlib.sha256()
            with open(dest.with_suffix(".part"), "wb") as f:
                for chunk in r.iter_content(1 << 20):
                    f.write(chunk); h.update(chunk)
        dest.with_suffix(".part").rename(dest)
        print(f"ok {dest} sha256={h.hexdigest()}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
