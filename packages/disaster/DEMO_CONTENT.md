# Disaster & Emergency — Features & Demo Content

Plugin: `lumina/disaster` **0.5.0**  
Scenario: Tripoli coastal flood (`DEMO-` prefix)  
Seed command: `DB_HOST=127.0.0.1 php artisan disaster:seed-demo --organization=1`

Idempotent: re-running the command updates the same `DEMO-*` rows.

---

## Release note — after `php artisan migrate`

The `split_shared_ew_basin_risk_layer` migration renames each org's basin-risk layer to `ew_basin_risk_{org}` (and, if an org already had one, logs a warning with both layer ids, repoints maps/dashboards and deletes the duplicate). The per-org views are created on publish, so **run this right after migrating** (with no option it publishes for every enabled org), or the basin-risk layer stays empty:

```bash
php artisan early-warning:publish-risk-layer          # all enabled orgs
php artisan early-warning:publish-risk-layer --organization=3   # one org
```

## Console modules (features)

### Prevention

| Feature | Route / UI | What it covers |
| --- | --- | --- |
| Overview | `/x/disaster` | Hub counts and module entry |
| Hazards | Hazards settings | Floodplain / fault / wildfire / demographic / EW risk layer IDs, risk weights, AVL skew, retention, official authority |
| Assets | Medical facilities, staging areas, critical sites | Hospitals, staging lots, shelters, dams, power, bridges, water, fuel, airport, telecom, depots; infrastructure condition inspections |
| Planning (evac) | Evac corridors | Named corridors linked to shelters |
| Exposure | Exposure page + `disaster:compute-proximity` / `disaster:exposure-report` | PostGIS asset exposure / risk index |

### Response

| Feature | Route / UI | What it covers |
| --- | --- | --- |
| COP / Overview | Common Operating Picture | Incidents, closures, activations, assets, AVL, FEMA community lifelines roll-up, briefing export |
| Activations | Warning → activation | From Core `WarningIssued` or demo; severity / urgency / certainty; advisory text |
| Escalation rules | Warning ack chain | Timeout → next role / channel |
| Evacuation orders | Draft → approve → issue → complete | Zone geometry, shelters, corridors, EN/AR text, population counters |
| Incidents | Incident workspace | Open incidents + ICS-lite (periods, IAP, roles, tasks, event log, SitRep / EDXL JSON) |
| Resource requests | Request workflow | Typed catalog, priority, EDXL-RM-ish envelope |
| Inventory | Warehouses + ledger | Stock-in only via ledger (no negative stock) |
| Volunteers | Volunteer roster | Skills, availability |
| Registry | Shelter registry | Beneficiaries, check-in/out, missing persons + reports (`disaster.registry`) |
| Closures | Road / bridge closures | Line geometries, validity window |
| AVL | Live units + pings | Engine / ambulance / boat / command |
| Public tokens | Public advisory portal | CAP / feed abilities |
| CAP + SMS | Message templates + dissemination | AR/EN CAP & SMS templates; `disaster:retry-dissemination` |
| Inter-agency workspace | Incident workspaces | Invite / revoke / expiry |
| Evac routing | `disaster:recompute-evac-routes` | Closure-aware corridors, capacity-aware assignments |
| EDXL exchange | `/x/disaster/api/v1/exchange` | HAVE / RM / SitRep / DE (token auth) |

### Recovery

| Feature | Route / UI | What it covers |
| --- | --- | --- |
| Damage reports | Damage assessment | Points, form schema, review/verify, habitability, photos, pre/post imagery |

### Permissions

`disaster.view`, `disaster.manage`, `disaster.field`, `disaster.dispatch`, `disaster.command`, `disaster.telemetry`, `disaster.ingest`, `disaster.public_publish`, `disaster.approve_evacuation`, `disaster.registry`

---

## Seeded demo content (org 1)

Anchor: Tripoli waterfront ≈ **32.8872°N, 13.1913°E**

### Medical facilities (3)

| ID | Name | Beds (avail) | Trauma |
| --- | --- | --- | --- |
| `DEMO-MF-TRIPOLI-CENTRAL` | Tripoli Central Hospital | 420 (86) | 1 |
| `DEMO-MF-FIELD-NORTH` | North Field Clinic | 60 (22) | 3 |
| `DEMO-MF-PEDS` | Pediatric Emergency Ward | 80 (31) | 2 |

### Critical sites (10)

| ID | Type | Name |
| --- | --- | --- |
| `DEMO-SHELTER-MEDINA` | shelter | Medina Evacuation Shelter (cap 800) |
| `DEMO-SHELTER-PORT` | shelter | Port Community Hall (cap 350) |
| `DEMO-DAM-WADI` | dam | Wadi Retention Dam |
| `DEMO-POWER-WEST` | power_plant | West Grid Substation |
| `DEMO-BRIDGE-CORNICHE` | bridge | Corniche Bridge |
| `DEMO-WATER-PLANT` | water_plant | Coastal Water Treatment |
| `DEMO-FUEL-DEPOT` | fuel_station | Emergency Fuel Depot |
| `DEMO-AIRPORT-MITIGA` | airport | Mitiga Staging Apron |
| `DEMO-TELECOM-TOWER` | telecom_tower | Coastal Telecom Mast |
| `DEMO-DEPOT-LOGISTICS` | supply_depot | City Logistics Depot |

Infrastructure inspections: dam (fair), bridge (good), power (good).

### Staging & corridors

- `DEMO-STAGING-A` — Corniche Staging Lot A (40 vehicles, fuel)
- `DEMO-STAGING-B` — University Parking Staging B (25 vehicles)
- `DEMO-Corridor North` → Medina shelter
- `DEMO-Corridor Inland` → Port shelter

### Warning / activation / evacuation

- Activation `DEMO-ALERT-FLOOD-001` — flood, Severe / Immediate / Likely, status `active`
- Escalation: 15 min → duty_officer (SMS); 45 min → incident_commander (email)
- 1 warning recipient + ack for actor user
- Evacuation order **issued** (EN + AR), ~18k estimated population, zone polygon over waterfront

### Incidents (3) + ICS on primary

| External ID | Title | Priority |
| --- | --- | --- |
| `DEMO-INC-FLOOD-CORNICHE` | Corniche inundation — Sector 1 | 1 |
| `DEMO-INC-RESCUE-PORT` | Water rescue — Port basin | 1 |
| `DEMO-INC-POWER-OUTAGE` | West grid partial outage | 2 |

On Corniche incident: 1 operational period, approved IAP v1, IC + Operations roles, 2 tasks, 1 SitRep, event log entry.

### Closures (2)

- `DEMO-CLOSURE-CORNICHE` — road, Severe
- `DEMO-CLOSURE-BRIDGE` — bridge, Moderate

### AVL (4)

`DEMO-ENG-01` (engine), `DEMO-AMB-07` (ambulance), `DEMO-BOAT-02` (boat), `DEMO-CMD-1` (command) — each with a recent ping.

### Damage (4) + form schema

| ID | Level | Notes |
| --- | --- | --- |
| `DEMO-DMG-01` | severe | Medina block A flood |
| `DEMO-DMG-02` | moderate | Corniche shop front |
| `DEMO-DMG-03` | destroyed | Port retaining wall |
| `DEMO-DMG-04` | minor | Roadway debris |

Default form schema: `DEMO-Residential Rapid` (structure type, water depth, access blocked).

### Inventory & volunteers

Resource types: bottled water, blankets, sandbags, first-aid kits (`DEMO-` names).  
Warehouse: `DEMO-Central Relief Warehouse` (stocked).  
1 approved water request for the Corniche incident.  
Volunteers: Amina Hassan, Omar El-Sharif, Sara Benali.

### Community lifelines (8)

Safety, Food/Shelter, Health, Energy, Communications, Transportation, HazMat, Water — mixed stable / at_risk / impacted statuses.

### CAP / SMS templates (4)

Flood EN+AR for `cap` and `sms` channels (approved).

### Registry

- Households `DEMO-HH-1001` … `1003` at Medina shelter + check-ins
- Missing person `DEMO-MP-001` (K. N.) + sighting report

### Public token

Label: `DEMO-Public advisory feed`  
Abilities: `feed`, `cap`  
Plain token is printed once by `disaster:seed-demo` (store securely; re-seed does not reprint an existing token).

---

## Re-seed / upgrade

```bash
# Apply Wave 2–3 migrations if needed
DB_HOST=127.0.0.1 php artisan plugin:upgrade disaster --no-interaction

# Seed or refresh DEMO-* content
DB_HOST=127.0.0.1 php artisan disaster:seed-demo --organization=1 --no-interaction
```

Implementation: `packages/disaster/src/Demo/DemoContentSeeder.php`  
Artisan: `disaster:seed-demo`

---

## NCMCD Wadi-26 exercise (Arabic, `NCMCD-W26-` prefix)

**تمرين افتراضي.** Arabic flash-flood exercise for المركز الوطني لإدارة الطوارئ والأزمات والكوارث, built from [`demo/ncmcd-scenario.md`](demo/ncmcd-scenario.md). All facilities, people and numbers are fictional.

### Command

```bash
docker exec laravel-app php artisan disaster:seed-ncmcd-demo            # create or refresh
docker exec laravel-app php artisan disaster:seed-ncmcd-demo --reset-passwords
```

- Needs the `disaster` and `early-warning` plugins installed, and the basins `TRP-MAJENIN`, `TRP-RABIE` and `TRP-HIRA` in `ew_basins`. Basins are reused, never created; a missing one is skipped and reported.
- In production it refuses unless both `--force` and `--i-understand-demo` are passed.
- **Passwords are never printed.** New passwords go only to `storage/app/private/ncmcd-demo-credentials.txt` (mode 0600, gitignored). The command prints just that path. Existing users keep their passwords unless `--reset-passwords` is used.

### Organization and users

The seeder creates its own organization (separate from org 1, with no `DEMO-` content). It finds that org by an explicit marker, `demo_key = ncmcd-w26` plus `exercise = true`, in the disaster and early-warning `organization_plugins.settings`, never by name. If an org with the same name exists without the marker, the command fails and changes nothing. Users are matched by email: if any demo email already belongs to an account outside the marked org (or exists before that org does), the command also fails before writing anything.

| Persona | Email | Core role | ICS role on INC-01 |
| --- | --- | --- | --- |
| مدير النظام | `admin@ncmcd-demo.test` | admin (org owner; approves the plan and the evacuation order) | — |
| ضابط المناوبة | `duty@ncmcd-demo.test` | admin (drafts the evacuation order) | operations |
| المسؤول عن البلاغ | `commander@ncmcd-demo.test` | editor | incident_commander |
| المسؤول الميداني | `field@ncmcd-demo.test` | viewer | safety |

All four users have `preferred_locale = ar`. They're also on the project «تمرين وادي-26 — طرابلس» (bbox 12.95,32.75 → 13.45,32.95).

> Editor and viewer can't use `disaster.command`, `approve_evacuation` or `disaster.registry`. Approvals and the shelter registry are shown by the director or the duty officer (PM decision, 1 Nov 2026).

### Timeline anchor

`NcmcdDemoSeeder::SEED_TIME_IS = '04:00'` is the single offset: seed time is treated as 04:00 on the exercise day, the flood peak. The early-warning risk peaks now (red for Majenin over the next 3 h, receding by about 09:00), and it lines up with incidents opened between 03:10 and 04:20. Steps the spec places after 04:00 carry future timestamps: the registry (05:00–06:10), the SitRep (06:00), damage (12:00) and the order close-out (16:00). **Re-run the seeder shortly before the demo** so "now" matches.

### What is seeded

- **Early Warning:** subscriptions for the 3 basins, 24 h of stored `ew_risk_assessments`, and `ew_forecasts` with source `ncmcd-demo` (shown by the warnings feed only for this org via `props.organization_id`). It also adds one alert rule, stored **disabled** so the scheduler never evaluates it against live weather, and one alert log. `ComputeRiskJob` skips orgs whose early-warning settings have `exercise = true`, so the stored risk isn't overwritten.
- **Activation:** `NCMCD-W26-ALERT-001`, `is_exercise = true` (shown with the «تمرين» badge). Duty officer acknowledgement at 00:20.
- **Disaster:** everything in spec §3–4:
  - 3 medical facilities, 7 critical sites, 3 staging areas and 1 corridor;
  - the evacuation order: drafted by the duty officer, approved and issued by the director. On PostGIS, `zone_geom` is clipped to the TRP-MAJENIN polygon; on SQLite, or with no basin geometry, it stays the spec rectangle;
  - 5 incidents, 4 AVL units, 3 closures (spec `Severe`→`major`, `Moderate`→`moderate`, matching the Closures UI) and 5 damage points with the «حصر سكني سريع» form;
  - the ICS package on INC-01: period, approved plan, 3 roles, 2 tasks and a SitRep;
  - inventory: 5 types, a warehouse and 2 requests;
  - 4 volunteers and 8 lifelines;
  - 3 CAP and 4 SMS templates in `ar`, approved, every text starting with «تمرين»;
  - the registry: 5 households with check-ins, and 1 redacted missing person with a sighting.
- **Nothing is sent:** no notifications, mail, SMS, queued jobs or `WarningIssued` events.

### Arabic display and shared data

- User-facing Arabic names, titles and notes carry no `NCMCD-W26-` code. Codes live in code/key columns (`external_id`, `site_id`, `source_alert_id` …) or `props.code`. The damage form name «NCMCD-W26-حصر سكني سريع» is the one exception, per the spec.
- The 4 default EW alert rules created on enable get Arabic names and are disabled, in the demo org only. Note: "Seed defaults" on the alert-rules page (or `early-warning:seed-alert-rules`) would re-add the English defaults, because they're matched by name.
- **Shared basins:** `ew_basins` is global. The seeder sets only `name_ar` (وادي المجينين / وادي الربيع / وادي الهيرة) and never the English `name`. That Arabic name shows for every org.

### Idempotency

Rows are keyed on `organization_id` plus their `NCMCD-W26-` code, or a stable Arabic name where the table has no code column. The evacuation order is keyed on its `NCMCD-W26-ALERT-001` activation. Re-runs update in place. The whole seed runs in one DB transaction: a failure keeps nothing, and the credentials file is written only after commit. On each run the seeder replaces this org's risk rows, the `ncmcd-demo` forecasts for the 3 basins and one AVL ping per unit. Covered by `packages/disaster/tests/NcmcdDemoSeederTest.php`.

### Walkthrough

Follow spec §6 (14 minutes) in [`demo/ncmcd-scenario.md`](demo/ncmcd-scenario.md):
- **Director:** overview, escalation rules, plan and evacuation approval (6:30–9:30), SitRep PDF.
- **Duty officer:** early-warning warnings, activation acknowledgement, CAP templates, incidents and closures, resources.
- **Commander:** ICS period, roles and tasks, COP.
- **Field officer:** AVL and damage.
