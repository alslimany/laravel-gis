# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

The primary user is a GIS editor inside an organization. They import spatial data, publish layers, build maps, and analyze them.

Admin, Editor, and Viewer are access roles inside that organization. They are not separate primary audiences.

## Product Purpose

A self-hosted web GIS console so one organization can keep its spatial data, maps, and people together. Success is an editor taking data from import through a published layer to a map they can analyze, without leaving the organization.

## Positioning

A self-hosted console for one organization: their data, their maps, their people. A public map portal or an analysis-only workbench does not own that combination.

## Operating Context

Editors work in a browser against a Docker stack: Nginx, Laravel, PostGIS, GeoServer, and Redis. The local app is served at port 8080, with Vite used during development.

The working loop is the console: dashboard, projects, data imports, layers, the map builder, then analysis, export, and sharing. Admins also manage groups, forms, webhooks, API tokens, and organization settings. People outside the organization can open a shared map, project, dashboard, or public form when someone issues a link.

## Capabilities and Constraints

Confirmed:

- Organization isolation. Data and people belong to one organization.
- Roles: Admin, Editor, and Viewer.
- Spatial storage and publishing stay on PostGIS and GeoServer.
- Imports (including spatial files and Excel), layers, feature editing, styling, vector tiles, attribute tables, and export.
- Map builder: draw, measure, identify, buffer, spatial and attribute query, geocode, print.
- Sharing links, groups, content access, forms, dashboards, catalog, webhooks, API tokens, and an in-app GIS assistant.
- Optional connections. A form, layer, map, or dashboard can link to another when that helps. None of those links is required to use either side:
  - Form and layer. A form may collect into a layer, and a layer may supply the fields a form uses. A form does not require a layer. A layer does not require a form.
  - Dashboard and layer. A dashboard may summarize a layer, and a layer may appear on a dashboard. Neither requires the other.
  - Dashboard and map. A dashboard may embed a saved map. A dashboard does not require a map. A map does not require a dashboard.
  - Map and layer. A map may include layers, and a layer may be placed on a map. A map does not require a layer. A layer does not require a map.

Open:

- The product name is Lumina GIS. The mark is a white corner and dashed diagonal on black. Organization names stay on the organization, not in the product wordmark.
- Whether 3D, offline sync, and routing belong in the product was not decided.

## Evidence on Hand

Implementation and operator docs live in the repository, including `README.md` and the GIS tools write-ups. There are no customer testimonials, case studies, press mentions, or pricing claims. Do not invent them.

## Product Principles

- Serve the editor's loop first: import, publish, map, analyze.
- Keep one organization's data, maps, and people together.
- Treat PostGIS and GeoServer as the spatial system of record.
- Keep Admin, Editor, and Viewer as distinct access, not one generic user.
