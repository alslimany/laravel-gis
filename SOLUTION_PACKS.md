# Solution Packs

## Start here

Three ideas borrowed from how Esri frames ArcGIS as a system of engagement, applied to this platform:

1. **Apps, not pages.** A solution pack installs a *destination* — a map, a form, a dashboard — that a
   named job can be done from. The **App Gallery** at `/apps` lists every installed workspace plus shared
   links and recent activity, so the pack is a place you go, not a feature you remember exists. Both are
   in the console sidenav: **Apps** sits right under Dashboard, and **Solution packs** (admins) sits in the
   Organize group beside Organization.
2. **Workflows are composed, not coded.** Each pack can declare an ordered runbook of steps (collect →
   notify → monitor → review → report). The gallery shows it inline; the pack builder edits it. No new
   engine — steps resolve to existing maps/forms/dashboards by name, and a missing target simply disables
   the step.
3. **An insight you cannot act on is a screenshot.** Running a query in the analysis panel produces a
   result; **Save as layer** turns that result into a real, typed, styled layer with its own table —
   publishable, editable, and linkable from dashboards. The saved analysis keeps a back-reference
   (`summary.resulting_layer_id`) so the catalog shows which insights became operational.

### Effort map

| Capability | Where | Effort |
| --- | --- | --- |
| Install a pack, get a working workspace | Organization → Solution packs | One click / one CLI command |
| Browse what the pack gave you | `/apps` (App Gallery) | Read-only view, no setup |
| Follow the pack's runbook | Gallery → pack → Workflow | Free — steps link to existing content |
| Answer a question over your data | Analysis panel → query | Existing tooling |
| **Operationalize the answer** | Analysis panel → *Save as layer* | New — one endpoint + table copy |
| Customize a pack | Duplicate → UI builder | Admin-only, validated |

### Suggested adoption sequence

1. **Install `aec-construction`** on a pilot organization — layers, relationships, sample features, and the
   whole workspace appear at once.
2. **Open `/apps`** and walk the workflow runbook: submit the public form from a phone, check the
   dashboard, review the starter map. This is the "does the destination hold up" test.
3. **Run a query** (e.g. buildings by status) and **Save as layer** — the insight becomes something you can
   style, publish to GeoServer, and share.
4. **Duplicate the pack** and edit the copy once real field requirements differ from the seed definition.

### Known gap

**Linear referencing (LRS)** — routes with measured referencing (chaining, calibration, event segments along
a route) is *not* covered by this platform: there is no measure-aware geometry type, no route/event table
model, and no along-route measurement in the analysis panel. DOT-style corridor work would need it as a
separate phase; everything else in the sequence above works without it.

---

Solution packs are installable blueprints of GIS content: typed layers with fields, domains and calculated
fields, parent/child relationships, sample features, and a ready-made workspace (starter map, public form,
dashboard). Installing a pack provisions everything into **one organization only** — it never touches other
organizations, and re-running an install is safe (idempotent).

There are two kinds of packs:

| Kind | Source | Editable | Example |
| --- | --- | --- | --- |
| Built-in | PHP class in `app/SolutionPacks/` | No (duplicate to customize) | `aec-construction` |
| Custom | Row in `solution_pack_definitions` (JSON) | Yes, in the UI builder | anything your admins create |

---

## Built-in pack: AEC / Construction

Slug: `aec-construction` — the pilot pack for architecture/engineering/construction tracking.

**Layers** (each with a default map style):

| Layer | Geometry | Notable fields |
| --- | --- | --- |
| Parcels | Polygon | `zoning` (domain), `land_area_m2`, calculated `land_area_ha` |
| Buildings | Polygon | `use_type` (domain), `floors`, `status` (domain), `year_built` |
| Utilities | LineString | `utility_type`, `material`, `diameter_mm` (domains) |
| Inspections | Point | `inspection_type`, `result` (domains), `inspector`, `inspection_date` |

**Relationships:** `Parcel buildings` (Parcels → Buildings via `parcel_id`),
`Building inspections` (Buildings → Inspections via `building_id`).

**Sample features:** 8 rows around (-122.4195, 37.7745), seeded on first install only (tables must be empty).

**Workspace:**

- Map — *AEC Site Overview* (Street basemap, zoom 16, all four layers, per-layer styles)
- Form — *Site inspection* (public share link, 5 fields matching the Inspections layer, two required selects)
- Dashboard — *AEC progress* (buildings/inspections count indicators, buildings-by-status bar, inspection-results pie)

---

## Installing a pack

### From the web UI

1. **Solution packs** in the sidenav's Organize group (admins only), or Organization → Solution packs.
2. Press **Install** on the pack card.
3. The flash message summarizes what was created, e.g.
   `AEC / Construction installed: 4 new layers, 2 relationships, 8 sample features, 1 map, 1 form, 1 dashboard.`
   A repeat install reports `already installed`.

### From the CLI

```bash
php artisan solution:pack:install aec-construction {organization_id}
php artisan solution:pack:install aec-construction {organization_id} --user={user_id}   # owner of created rows
php artisan solution:pack:install aec-construction {organization_id} --publish          # also publish to GeoServer
```

The owner defaults to the organization's first admin. Custom packs install by their slug too.

### Idempotency rules

- **Layers** are matched by name + `metadata.solution_pack` slug; existing layers are kept, missing ones created.
- **Relationships** are matched by parent/child/foreign key; the FK column is added only when missing.
- **Sample features** are seeded only into tables that are still empty.
- **Workspace items** (map, form, dashboard) are matched by name within the organization.

---

## Publishing pack layers to GeoServer

Pack layers install **unpublished** so a fresh install never depends on GeoServer. Publishing is an explicit,
separate step:

- **Settings / Solution packs list** — when a pack has unpublished layers, a `Publish N layers` button appears
  (admins). Each layer is published synchronously; successes call `markAsPublished`, failures are collected and
  reported in the flash message instead of aborting the batch.
- **CLI** — `solution:pack:install {slug} {org} --publish` publishes unpublished pack layers right after install.
- **Single layer** — the existing per-layer *Publish* action on the layer page still works.

Notes: the workspace is `org_{organization_id}`, the datastore comes from `config('geoserver.datastore')`,
and job failures are logged per layer (`GeoServerPublisher`). Unpublished layers do **not** break the starter
map — the app's vector tile endpoint reads PostGIS directly.

---

## Building packs in the UI (admins)

Entry point: **Organization → Solution packs → Build a pack** (or `/solution-packs`).

1. **New pack** — set name (slug auto-fills), description.
2. **Layers** — add layers, pick geometry (Polygon/LineString/Point/MultiPolygon/table), then fields:
   name, label, type, comma-separated allowed values, required. Optional *Default style* block sets fill/stroke.
3. **Relationships** — parent, child (dropdowns of your layers), foreign key column name.
4. **Starter map** — name, basemap, center/zoom, which layers appear.
5. **Form** — name, target layer, public share link flag, schema rows (column, label, type, options).
6. **Dashboard** — widget rows: type, title, layer, group-by, aggregation.
7. **Save**, then **Install** it from the pack list (or Organization settings).

**Duplicate a built-in pack** to get an editable copy that already contains the full AEC definition,
including its 8 sample features. Sample features are preserved as-is (the builder does not edit them).

Validation runs server-side (`DefinitionValidator`) and errors surface in the page: reserved slugs
(built-in slugs are off-limits), slug format, unique layer names, field name format, relationship/map/form
layers must exist in the pack, widget types must be known.

Custom packs are **owned by the organization that created them** — other organizations cannot see or install
them (built-ins are global).

---

## Sharing with the field

- **Public form**: each form gets an unguessable `/f/{share_token}` link. Submissions validate against the form
  schema *and* the layer schema (domains, required fields) and write a real feature with geometry
  (latitude/longitude → WKT). Disable *Public* on the form if you do not want it reachable.
- **Maps / dashboards** keep their existing share links; the starter map installs as private.

---

## The app gallery and workflow runbook

**`GET /apps`** (App Gallery, linked from the Dashboard's *Browse apps* and the sidenav **Apps** item)
renders three lists for the signed-in organization:

- **Installed apps** — one row per installed pack: the starter map, the public form (`/f/{token}` when
  public, otherwise the internal form page), and the dashboard, each linking straight into it.
- **Shared links** — publicly shareable maps, dashboards, and forms (`is_public` + `share_token`).
- **Recent** — the eight most recently updated maps, dashboards, and forms.

Packs that declare a `workflow` also render a **runbook**: an ordered list of steps, each typed
(`form` / `dashboard` / `map` / `text`), resolved against the organization's content *by name* at request
time. A step whose target no longer exists renders as "not found — step disabled" instead of failing the
page. The same resolved runbook is exposed on the pack list (`workflow` prop) and editable in the UI
builder under **Workflow runbook** (title, type, target, description). Validation enforces that `form`,
`dashboard`, and `map` steps point at a target that actually exists in the pack definition.

---

## Saving an analysis as a layer

An analysis result records *which* features matched; **Save as layer** materializes them into a layer of
their own so the insight can be styled, shared, and published — the "operationalize the answer" step.

- **UI** — in the analysis panel's Results section, next to *Save for dashboards*: the result is saved
  first if needed, then `POST /api/analysis/results/{id}/save-as-layer` copies the matched rows.
- **What is copied** — a new table `analysis_result_{uniqid}` with the source column types (read via
  `format_type()` on PostGIS, `PRAGMA table_info()` on SQLite), source ids preserved, an
  `analysis_result_id` back-reference, the geometry column, and a GIST index on PostGIS. `LayerField`
  aliases/domains are copied too, plus `geometry_type`, `style_config`, and `project_id`.
- **Back-reference** — the layer's `metadata` records `analysis_result_id` + `source_layer_id`, and the
  analysis result's `summary.resulting_layer_id` points back at the layer (surfaced in the dashboard
  editor's analysis catalog).
- **Guards** — org membership (404), `LayerPolicy::create` (403), non-empty `feature_ids` and at least one
  surviving source row (422). Layer names get a ` (result)` suffix, then ` (2)`, ` (3)`… on collision.
- **Publishing** — opt-in via `publish=1`, which runs the same `GeoServerPublisher` path as pack layers;
  by default the new layer stays unpublished.

---

## Architecture

```
app/SolutionPacks/
├── SolutionPack.php          abstract contract: slug(), name(), description(), definition(), workflow()
├── AecConstructionPack.php   built-in definition (PHP array), incl. the 5-step workflow runbook
├── StoredPack.php            adapter: definition JSON from solution_pack_definitions → same contract
├── PackCatalog.php           shared listing (installed flag, contents, unpublished count, resolved workflow)
├── WorkflowResolver.php      runbook steps → {title, kind, target, href} against org content by name
└── DefinitionValidator.php   structural validation → Inertia error keys (definition.layers.0.name …)

app/Http/Controllers/
├── SolutionPackRequest.php   admin CRUD + duplicate for the UI builder
└── AppGalleryController.php  GET /apps → installed apps, shared links, recent (with runbooks)

app/Services/
├── SolutionPackInstaller.php driver-aware provisioning (PostGIS DDL / sqlite test DDL)
├── GeoServerPublisher.php    synchronous batch publish with per-layer failure collection
└── AnalysisLayerSaver.php    analysis result → new table + Layer (driver-aware copy, GIST, backrefs)
```

**Definition JSON keys** (all optional except `layers`): `layers` (name, geometry_type, style, fields[]),
`relationships` (name, parent, child, foreign_key), `features` (layer, attributes, wkt, refs),
`maps`, `forms`, `dashboards` (widgets reference layers **by name**; the installer resolves them to ids
and normalizes widgets through `DashboardWidgetDocument`), and `workflow` (ordered steps:
title, description, kind = `form`|`dashboard`|`map`|`text`, target — targets must name a layer-backed
map/form/dashboard in the same pack; `text` steps carry only a description).

Routes (admin, inside the organization middleware):

```
GET    solution-packs                  list
GET    solution-packs/create           builder (new)
POST   solution-packs                  store
GET    solution-packs/{id}/edit        builder (edit)
PUT    solution-packs/{id}             update
DELETE solution-packs/{id}             delete (installed layers are kept)
POST   solution-packs/{slug}/duplicate clone built-in or own pack
POST   organization/solution-packs/{slug}          install (built-in or custom)
POST   organization/solution-packs/{slug}/publish  publish pack layers to GeoServer
GET    apps                                        app gallery (any member)
POST   api/analysis/results/{analysis}/save-as-layer   materialize a result as a layer
```

---

## Production notes

- **PostgreSQL/PostGIS is the production database.** The migration, the pack install DDL
  (`geometry(Geometry, 4326)` + GIST indexes), feature seeding (SRID 4326), and calculated fields were
  validated against a live PostGIS instance. SQLite exists only for the in-memory PHPUnit harness; driver-aware
  branches in the installer/FeatureService are test-support code and must not be "simplified" away.
- Vector tile endpoints (`/api/layers/{id}/tiles/…`) are PostGIS-only (ST_AsMVT); the starter map is written
  for production.
- Publishing is opt-in (`--publish` or the Publish button) so installs stay deterministic on machines without
  GeoServer access.

## Current limitations

- Custom packs are organization-scoped; sharing a custom pack across organizations requires a future
  "global/community packs" feature (built-ins already install anywhere).
- The builder does not edit sample features (they ride along when you duplicate; a JSON editor may come later).
- The builder adds at most one map, form, and dashboard per pack (extra entries imported via duplicates are
  kept and editable, but the header buttons manage the list as a whole — Remove clears it).
- Deleting a pack never removes already-installed layers — clean up layers from the Layers page if needed.
- No linear referencing (LRS): route measure calibration, along-route events, and measure-based querying are
  out of scope (see "Known gap" at the top).
