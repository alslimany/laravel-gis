#!/usr/bin/env python3
"""Download Copernicus DEM GLO-30 tiles for the AOI (not committed; see .gitignore).

Usage: python tools/download_dem.py [--aoi tools/aoi.json] [--out data/dem]
Source: Copernicus DEM GLO-30, public AWS Open Data bucket (no auth).
Every tile is verified against the SHA-256 pinned in aoi.json (dem.sha256);
a missing pin or a mismatch is a hard failure (fail closed).
"""
import argparse, json, pathlib, sys, hashlib
import requests

def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--aoi", default=str(pathlib.Path(__file__).with_name("aoi.json")))
    ap.add_argument("--out", default=str(pathlib.Path(__file__).parents[1] / "data" / "dem"))
    a = ap.parse_args()
    aoi = json.load(open(a.aoi))
    pins = aoi["dem"].get("sha256", {})
    out = pathlib.Path(a.out); out.mkdir(parents=True, exist_ok=True)
    for tile in aoi["dem"]["tiles"]:
        want = pins.get(tile)
        if not want or len(want) != 64:
            print(f"ERROR no pinned sha256 for {tile} in aoi.json", file=sys.stderr); return 1
        dest = out / f"{tile}.tif"
        if dest.exists():
            got = sha256_file(dest)
            if got == want:
                print(f"ok (cached) {dest}"); continue
            print(f"checksum mismatch for cached {dest}; re-downloading"); dest.unlink()
        url = f'{aoi["dem"]["base_url"]}/{tile}/{tile}.tif'
        print(f"GET {url}")
        part = dest.with_suffix(".part")
        h = hashlib.sha256()
        with requests.get(url, stream=True, timeout=120) as r:
            r.raise_for_status()
            with open(part, "wb") as f:
                for chunk in r.iter_content(1 << 20):
                    f.write(chunk); h.update(chunk)
        if h.hexdigest() != want:
            part.unlink()
            print(f"ERROR sha256 mismatch for {tile}: got {h.hexdigest()} want {want}", file=sys.stderr); return 1
        part.rename(dest)
        print(f"ok {dest} sha256={want}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
