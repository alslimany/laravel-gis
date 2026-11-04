# Lumina GIS — docs index

Team entry point for architecture decisions, security notes, runbooks, and package docs. Root [README.md](../README.md) mirrors this list under **Documentation**.

## Architecture decision records

| ADR | Status | Topic |
|---|---|---|
| [0001-plugin-architecture.md](adr/0001-plugin-architecture.md) | Proposed | Plugin host and contract |
| [0002-desktop-gis-connectivity.md](adr/0002-desktop-gis-connectivity.md) | **Accepted** (2026-11-04) | Desktop GIS (OGC API, WFS-T, GeoPackage); D1–D7 locked; A1–A4 open PM defaults |

## Security

| Doc | Story |
|---|---|
| [db-roles.md](security/db-roles.md) | DG-0.1 least-privilege roles (`docker/postgres/roles.sql`) |

## Runbooks

| Doc | Story |
|---|---|
| [upgrade-pg17-postgis35-geoserver228.md](runbooks/upgrade-pg17-postgis35-geoserver228.md) | DG-0.6 upgrade; **do not** apply locally until DG-0.7 / NCMCD demo gate |

## First-party package docs

| Package | README |
|---|---|
| `lumina/desktop-connect` (DG-1.4) | [packages/desktop-connect/README.md](../packages/desktop-connect/README.md) |
| `lumina/disaster` | [packages/disaster/README.md](../packages/disaster/README.md) |
| `lumina/early-warning` | [packages/early-warning/README.md](../packages/early-warning/README.md) |

## Other notes in this folder

- [core-plugin-contracts.md](core-plugin-contracts.md)
- [plugins-scheduler.md](plugins-scheduler.md)
- [plugin-host-security-review.md](plugin-host-security-review.md)
