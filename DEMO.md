# Core loop demo

A short path for a clean organization: import a dataset, publish the layer, style it, put it on a map, then analyze, export, and share. Forms and dashboards are optional and are not part of this path.

The queue worker must be running (`php artisan queue:work`, or the `queue` service in Docker). Imports stay on the import page until that worker finishes the file. GeoServer must be reachable for Publish. Vector maps still draw from PostGIS tiles after a layer is published.

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

## Checks that are out of this script

- A form does not need a layer, and a dashboard does not need a layer or a map.
- 3D, routing, and a basemap marketplace are not part of this path.
