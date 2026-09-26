# Core loop demo

A short path for a clean organization: import a dataset, publish the layer, style it, put it on a map, then analyze, export, and share. Forms and dashboards are optional and are not part of this path.

The queue worker must be running (`php artisan queue:work`, or the `queue` service in Docker). Imports stay on the import page until that worker finishes the file. GeoServer must be reachable for Publish. Vector maps still draw from PostGIS tiles after a layer is published.

## Live smoke

A live walkthrough of this script needs three services up: **PostGIS**, **GeoServer**, and **Redis** (the queue). With Docker Compose those are the `postgis`, `geoserver`, and `redis` services, plus the `queue` worker.

GitHub Actions is not the check for this path. Run this local filter instead. It is the CI substitute and covers `CoreLoopDemoTest`, `DataImportTest`, `LayerTest`, and `ExportTest`:

```bash
php artisan test --filter='CoreLoopDemoTest|DataImportTest|LayerTest|ExportTest'
```

From the app container:

```bash
docker compose exec laravel-app php artisan test --filter='CoreLoopDemoTest|DataImportTest|LayerTest|ExportTest'
```

**GeoServer URL.** Inside Docker Compose, the app must call GeoServer by service name on the container port **8080**:

```env
GEOSERVER_URL=http://geoserver:8080/geoserver
```

`.env.example` already sets that. Compose publishes GeoServer to the host as `8081:8080`, so a browser on the host uses `http://localhost:8081/geoserver`. That host port is the wrong value for `GEOSERVER_URL`. The app container is on the Compose network, where the `geoserver` service listens on **8080**. `GEOSERVER_PUBLIC_URL` (`http://127.0.0.1:8081/geoserver` in `.env.example`) is the host-facing URL and is a separate setting.

The same notes are in [TESTING_GUIDE.md](TESTING_GUIDE.md#live-demo-smoke-ci-substitute).

## Script

1. Sign in as an editor on an organization that has no imports, layers, or maps.
2. Open **Data imports** and choose **Import dataset**. Upload a zipped shapefile, GeoJSON, KML, CSV, or Excel file. A lone shapefile sidecar (`.prj`, `.shx`, `.dbf`) is rejected and the import is marked failed, with a link to try another file.
3. Stay on the import page until the status is **Completed**, then choose **Open layer**. The layer is still a draft.
4. Choose **Publish**. The page stays on the layer. On success the status is **Published**. If GeoServer cannot publish the table, the layer stays a draft and the error is shown on the page.
5. Choose **Edit**, set fill and stroke, and **Save style**.
6. Choose **Open in map**. The layer is already on the map and selected, so **Style** in the map workspace edits that layer. Save the map. From a saved map, **Share** is on the map view.
7. Alternatively, open **Maps** → **Open map builder** → **Add layer** → **From GeoServer**. Only published layers are listed. If the list is empty, the panel links back to layers and imports.
8. In **Analysis**, run a buffer, spatial query, or attribute query. A failed analysis states why instead of doing nothing. **Export as GeoJSON**, **Export as CSV**, and **Export Map Config** download a file and leave you on the map. A failed layer export from the layer page returns to that layer with the reason. **Export as Image** downloads a PNG.
9. On the saved map, choose **Share**, turn on **Make this map public**, and copy the link. The page stays on Share. Open the link while signed out: the basemap and the map's vector layers load. The link does not expose other layers in the organization.

## Guests

A signed-out guest with a public map link gets that map's basemap and vector layers. A map with no vector layers still shows its basemap. The link does not include other layers in the organization.

A public dashboard shows its widgets to guests. A saved map on that dashboard is included only when the map is public, and guests get that map's vector tiles. A private map is omitted: its title, id, and layer list are not on the public page. A map widget pointed at a layer serves that layer's tiles. Guests do not get tiles for another organization, a private map, or a layer that is not on the share.

## Checks that are out of this script

- A form does not need a layer, and a dashboard does not need a layer or a map.
- A dashboard widget may summarize a saved analysis or query result. That link is optional.
- 3D, routing, and a basemap marketplace are not part of this path.
