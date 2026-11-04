# Disaster plugin — agent briefs (Waves 1–4)

Ready-to-launch prompts. Wave 1 Agents A/B are in flight; C/D wait on A; E waits on B.
Offline field app stays at **Wave 4 (v0.6)** — do not raise priority in this program.

## Wave 1 follow-ons

### Agent C — Warning acknowledgement + escalation
- Notion: https://app.notion.com/p/4077a2eb2f3681c6b6fdc5b2fa007962
- Depends on: `dm_activations` + WarningIssued listener (Agent A)
- Tables: `dm_warning_recipients`, `dm_warning_acks`, `dm_escalation_rules`, append-only audit log
- Job/command: `disaster:escalate-warnings` every minute
- Permissions: `disaster.dispatch` ack; `disaster.manage` rules
- Migration timestamp suggestion: `2026_10_28_040000_*`

### Agent D — Evacuation order approval + execution
- Notion: https://app.notion.com/p/4077a2eb2f368129824dcb1020601c1b
- Depends on: activations (Agent A)
- Table: `dm_evacuation_orders` + execution counters; gate `disaster.approve_evacuation`
- States: draft → pending_approval → approved → issued → in_progress → completed|cancelled
- Migration timestamp suggestion: `2026_10_28_050000_*`

### Agent E — Spatial exposure + risk index
- Notion: https://app.notion.com/p/4077a2eb2f3681068729eb7b96252ecb
- Depends on: critical infra + `downstream_zone_layer_id` (Agent B)
- Replace haversine in `ProximityComputer` with PostGIS; extend `dm_exposure_summaries`; scenarios
- Migration timestamp suggestion: `2026_10_28_060000_*`

## Wave 2 (v0.4) — complete

1. **Agent F (blocking):** ICS-lite — https://app.notion.com/p/4077a2eb2f36812baea2eae170a7abcb — migration `070000`
2. **After F:** Resource requests / inventory / volunteers — https://app.notion.com/p/4077a2eb2f36818e9642ecde0bff0561 — `080000`
3. **After F:** Inter-agency workspace — https://app.notion.com/p/4077a2eb2f368133aeb2d93bf3725cbf — `090000`
4. **After F:** COP + Community Lifelines — https://app.notion.com/p/4077a2eb2f3681608d47fdd73492120c — `100000`

## Wave 3 (v0.5) — complete

1. Advanced evacuation routing (pgRouting default ADR) — https://app.notion.com/p/4077a2eb2f36816487d6efbdf6bcfef4 — `110000`
2. Structured damage assessment — https://app.notion.com/p/4077a2eb2f36813187aec1b57c509427 — `120000`
3. Shelter occupancy / beneficiaries / missing persons — https://app.notion.com/p/4077a2eb2f3681b3b72cf1b37cb3c742 — `130000` (legal review before PII fields)
4. Full CAP 1.2 + Arabic templates + SMS adapter — https://app.notion.com/p/4077a2eb2f368165a00cc6dbeb50b915 — `140000`
5. EDXL-HAVE / RM / SitRep / DE exchange — https://app.notion.com/p/4077a2eb2f3681e7bc90c4e54927d8cf — `150000`

## Wave 4 (v0.6)

1. Drills / AAR / KPIs — https://app.notion.com/p/4077a2eb2f36818e998ed9f67e68dd97
2. Offline field app + sync API — https://app.notion.com/p/4077a2eb2f3681ca8ab9ce96f7741698 (**kept here**)
3. Recovery projects / aid / debris — https://app.notion.com/p/4077a2eb2f368159926dc1d7ec50b89d
