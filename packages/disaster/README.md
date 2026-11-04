# Lumina Disaster & Emergency Management (`lumina/disaster`)

First-party plugin for mitigation assets, evacuation planning, incident dispatch, AVL ingest, and public advisory dissemination.

Version **0.5.0** (Wave 3 complete) — see `docs/adr/0001-plugin-architecture.md`.
Agent briefs for v0.6: `docs/wave-agent-briefs.md`.

## Install / upgrade

```bash
composer require lumina/disaster:^0.5
php artisan plugin:install disaster
# enable per org via Extensions UI or PluginManager toggle
php artisan plugin:upgrade disaster   # after pulling Wave 1–3 migrations
```

Schema changes only via `plugin:upgrade` (ADR D15). Migrations live in `packages/disaster/database/migrations` (`dm_*` tables).

## Features (0.3 Wave 1)

- Org-scoped medical facilities, staging areas, critical sites, evac corridors
- Critical infrastructure types: dam, reservoir, water/wastewater plants, telecom, fuel, airport, port, field hospital (plus legacy shelter/bridge/power/depot)
- Infrastructure condition & inspection records (`dm_infrastructure_conditions`) with overdue list on Assets
- Dam fields including `downstream_zone_layer_id` for dam-failure exposure scenarios
- **Warning-to-Activation:** Core `WarningIssued` event → `dm_activations` (idempotent; no `ew_*` reads)
- **Warning ack + escalation:** recipients, acks, rules, append-only audit; `disaster:escalate-warnings` every minute
- **Evacuation orders:** draft → approved → issued → completed with `disaster.approve_evacuation` (no self-approve)
- Spatial exposure engine (`ExposureComputer`, PostGIS) + risk index; SQLite keeps deprecated haversine fallback
- Zone/scenario summaries (`disaster:compute-proximity`, `disaster:exposure-report`)
- Incidents + AVL ingest APIs, public token portal, geofence check, asset layer publish
- Inertia console under Mitigation / Response / Recovery modules

## Wave 2 (complete)

- **ICS-lite:** operational periods, IAP draft/approve (`disaster.command`), ICS roles, task board, append-only `dm_event_log`, SitRep + EDXL-friendly JSON/PDF
- **Resource requests / inventory / volunteers:** typed catalog, warehouses, ledger-only stock (no negatives), request workflow through delivered/cancelled, nearest-warehouse matcher, volunteers console
- **Inter-agency workspace:** plugin-only grants (`dm_incident_workspaces` / members); invite/revoke/expiry; `IncidentWorkspaceAccessService`; hourly `disaster:expire-workspace-members`
- **COP + Community Lifelines:** `/x/disaster/cop` polled snapshot (incidents, closures, activations, assets, optional AVL); eight FEMA lifelines with status roll-up; DomPDF/HTML briefing export

### EDXL-RM mapping (lite)

`dm_resource_requests.edxl_json` stores a `EDXLResourceMessage` envelope via `ResourceRequestExporter`:

| App field | EDXL-RM-ish path |
| --- | --- |
| `incident.external_id` | `incidentID` |
| `resourceType.{name,category,capability,unit}` | `resourceInformation.resourceType` |
| `quantity` / `priority` / `needed_by` | `quantity` / `priority` / `dateTimeNeeded` |
| `status` | `resourceInformation.status` |
| `assignments[]` | `assignmentInformation` (warehouse / AVL unit source) |
| `notes` | `resourceInformation.notes` |

## Wave 3 (complete)

- **Evacuation routing:** closure-aware / capacity-aware corridor computation (`RoutingEngine`, pgRouting default ADR); `dm_road_network_edges`, `dm_evac_assignments`; `disaster:recompute-evac-routes`
- **Damage assessment:** structured forms, photos (max 5), submit→reviewed→verified|rejected; verified-only aggregates; pre/post imagery registration
- **Shelter registry:** check-in/out occupancy ledger, beneficiaries, missing persons + matching; `disaster.registry`; public aggregates only; `disaster:prune-registry` (see `docs/registry-privacy.md`)
- **CAP 1.2 + SMS:** full Alert/Update/Cancel, AR/EN templates, SMS gateway adapters, test mode, cached public CAP feed; `disaster:retry-dissemination`
- **EDXL exchange:** HAVE / RM / SitRep / DE under `/x/disaster/api/v1/exchange` (token auth); docs in `docs/edxl-exchange.md`

## Permissions

`disaster.view`, `disaster.manage`, `disaster.field`, `disaster.dispatch`, `disaster.command`, `disaster.telemetry`, `disaster.ingest`, `disaster.public_publish`, `disaster.approve_evacuation`, `disaster.registry`

Request workflow requires `disaster.dispatch`; inventory types/warehouses/ledger adjustments require `disaster.manage`; registry PII requires `disaster.registry`; console lists require `disaster.view`.

## Config

`config/disaster.php` — retention, exposure/risk weights, warning_ack timeouts, geofence, routing, advisory authority env keys.
