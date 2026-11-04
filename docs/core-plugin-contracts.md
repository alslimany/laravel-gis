# GIS Core → plugin contracts (Phase 0)

Source of truth: Notion [Project Guide & System Architecture](https://app.notion.com/p/4047a2eb2f36810ab8dbf749969f9dae).

## Golden rules (short)

1. Plugins depend on Core; Core never references a plugin.
2. Plugins talk to Core through contracts — not ad-hoc Core models/services.
3. Plugins do not copy base geodata; they reference Core layers by ID.
4. Plugin outputs return through Core (Layer + GeoServer publish).
5. Plugins own their tables/routes/jobs/UI; enablement is per organization.

## Contracts (shipped)

| Contract | Binding | Purpose |
| --- | --- | --- |
| `App\Contracts\LayerCatalog` | `EloquentLayerCatalog` | Find/list org layers; list features with org check |
| `App\Contracts\LayerPublisher` | `CoreLayerPublisher` | Upsert Layer + optional GeoServer SQL view (+ SLD) |
| `App\Support\OrganizationContext` | concrete | Current org id / org-admin helper for plugins |

## Events (shipped)

Dispatched from `App\Models\Layer` lifecycle:

- `App\Events\LayerCreated`
- `App\Events\LayerUpdated`
- `App\Events\LayerDeleted`

Early Warning listens for layers tagged `metadata.plugin=early-warning` or `metadata.kind=basins`.

### WarningIssued (Early Warning → Disaster via Core)

Core event: `App\Events\WarningIssued` carrying immutable DTO `App\Support\WarningIssuedPayload`.

| Field | Type | Notes |
| --- | --- | --- |
| `source` | string | Emitting plugin key, e.g. `early-warning` |
| `source_alert_id` | string | Opaque ID from the emitter (e.g. `ew_alert_logs.id`) |
| `organization_id` | int | Tenant |
| `hazard_type` | string | e.g. `flood` |
| `severity` | string | CAP-like: Extreme / Severe / Moderate / Minor / Unknown |
| `urgency` | string | CAP-like: Immediate / Expected / Future / Past |
| `certainty` | string | CAP-like: Observed / Likely / Possible / Unlikely / Unknown |
| `valid_from` / `valid_to` | datetime \| null | Validity window |
| `affected` | list | GeoJSON feature/geometry objects **or** Core feature IDs |
| `advisory_text` | string | Operator-facing advisory copy |
| `is_exercise` | bool | Default `false` |

**Golden rule 6:** Disaster must never read `ew_*` tables. Early Warning dispatches `WarningIssued` after creating an alert log; Disaster upserts `dm_activations` on `(organization_id, source, source_alert_id)` with status `proposed`. Core never imports either plugin.

## Open decisions (locked for now)

1. **Basins:** Target = normal Core layer. Today `ew_basins` is a transitional global catalog with optional `layer_id` link. Geometry-of-record migration onto Core-only is a separate task.
2. **Layer events:** Yes — Core emits them (above).
3. **Org ↔ areas:** Plugin `ew_subscriptions` by `basin_code` (global weather + org subscription). Remains plugin-owned.

## Repo reality vs proposed `packages/admin`

Admin (orgs, users, roles, Extensions enable/install) still lives in the Core app (`app/`, `resources/js/Pages/Extensions`). Extracting `packages/admin` is not required for Phase 0.
