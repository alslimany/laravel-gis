# Lumina Desktop GIS Connect (`lumina/desktop-connect`)

First-party plugin for QGIS / ArcGIS connectivity: deny-by-default per-layer grants, later OGC API Features, GeoServer AuthKey, and GeoPackage check-out/check-in.

Version **0.1.0** (DG-1.4 scaffold) — architecture in [`docs/adr/0002-desktop-gis-connectivity.md`](../../docs/adr/0002-desktop-gis-connectivity.md). Companion backlog: `~/Herd/lumina-desktop-gis-backlog.md`.

## Status (what ships today)

| Area | State |
|---|---|
| Plugin manifest / provider / nav (EN/AR) | Done (DG-1.4) |
| Off by default; `plugin:desktop-connect` → 404 when disabled | Done (ADR-0001 D3/D5) |
| `dc_grants` / `dc_grant_events` + access screen routes | Scaffold / early DG-1.5 |
| OGC API Features Part 1+4 | Not yet (DG-1.7 / DG-1.8) |
| GeoPackage check-out/check-in | Not yet (DG-2.x) |
| GeoServer AuthKey web-service mapper | Not yet (DG-1.9) |
| FeatureServer spike | Not yet (DG-3.1) |

Do not invent endpoints or UI beyond what is in this package and the ADR.

## Install / enable

```bash
# package is path-required from the monorepo (see root composer.json)
php artisan plugin:install desktop-connect
# enable per org via Extensions UI or PluginManager toggle
```

Schema changes only via `plugin:install` / `plugin:upgrade` (ADR-0001 D15). Migrations live in `database/migrations` (`dc_*` tables only — the plugin must not alter core tables; see ADR-0002 §7).

## Routes (when enabled)

Mounted under `/x/desktop-connect` with `web`, `auth`, `organization`, `plugin:desktop-connect`:

| Method | Path | Name | Notes |
|---|---|---|---|
| GET | `/` | `desktop-connect.index` | Plugin home |
| GET | `/ping` | `desktop-connect.ping` | Health/smoke |
| GET | `/grants` | `desktop-connect.grants.index` | Desktop connections screen |
| POST | `/grants` | `desktop-connect.grants.store` | Create grant |
| DELETE | `/grants/{grant}` | `desktop-connect.grants.destroy` | Revoke grant |

## Related docs (team index)

- [ADR-0002](../../docs/adr/0002-desktop-gis-connectivity.md) — accepted architecture (D1–D7 locked 2026-11-04; A1–A4 still open PM defaults)
- [ADR-0001](../../docs/adr/0001-plugin-architecture.md) — plugin host contract
- [DB roles (DG-0.1)](../../docs/security/db-roles.md) — least-privilege roles
- [Upgrade runbook (DG-0.6)](../../docs/runbooks/upgrade-pg17-postgis35-geoserver228.md) — PG17 / PostGIS 3.5 / GeoServer 2.28.6 (gated by DG-0.7)

## Permissions

- `desktop-connect.view` — see Desktop connections
- `desktop-connect.manage` — grant / revoke

## Config

`config/desktop-connect.php` is merged as `desktop-connect`. Keep operational secrets out of config; desktop keys stay in Sanctum (later DG-1.6).
