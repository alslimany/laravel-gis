# ADR-0001: Evacuation routing engine

- Status: Accepted
- Date: 2026-10-28
- Wave: 3 (v0.5) — advanced evacuation routing
- Notion: https://app.notion.com/p/4077a2eb2f36816487d6efbdf6bcfef4

## Context

Evacuation corridors today are manual polylines (`dm_evac_corridors`, `source` implied manual). Wave 3 requires closure-aware, capacity-aware zone→shelter routing with estimated distance and travel time. Candidate engines:

1. **pgRouting** (inside PostgreSQL/PostGIS) on org-scoped `dm_road_network_edges`
2. External engines (**OSRM**, **Valhalla**) via HTTP

Tests run on SQLite without PostGIS/pgRouting. Production GIS already uses PostGIS.

## Decision

**pgRouting is the default production engine**, behind a `RoutingEngine` contract so OSRM/Valhalla can ship later as adapters without changing `EvacuationRoutingService`.

| Environment | Binding |
|-------------|---------|
| PostgreSQL with pgRouting | `PgRoutingEngine` |
| SQLite / missing extension / tests | `NullRoutingEngine` (in-memory Dijkstra + straight-line fallback) |

Config: `disaster.routing.engine` = `auto` | `pgrouting` | `null`.

Computed corridors persist with `source = computed`; operators keep `source = manual` rows.

## Consequences

- Road network lives in `dm_road_network_edges` (org-scoped); OSM import / Core layer publish is out of this ADR’s schema but feeds this table.
- Active `dm_closures` mark intersecting (or matching) edges blocked before pathfinding; recompute is triggered when closures change.
- Shelter assignment enforces `sum(population) ≤ CriticalSite.capacity` unless `override_reason` is set.
- External engines remain future adapters implementing the same contract; no new Composer packages for this wave.

## Performance target

For a **city-scale seeded network** (≈10k edges, ≤50 zones → shelters in one batch):

- **p95 wall time &lt; 30 seconds** for `EvacuationRoutingService::recompute()` (apply closures + pathfind + capacity assign + persist corridors/assignments).
- Measured via `dm_routing_runs.metrics.duration_ms` on representative seed data.

If the target is missed, prefer graph pruning / precomputed contraction hierarchies inside pgRouting before introducing an external engine.