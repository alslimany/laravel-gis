# EDXL partner exchange (HAVE / RM / SitRep / DE)

GeoJSON console and ingest APIs remain the primary integration path. This package adds a **partner exchange** path for EDXL-HAVE, EDXL-RM, EDXL-SitRep, and EDXL Distribution Element (DE) using a pragmatic XML subset.

Fixtures: [`fixtures/edxl/`](fixtures/edxl/).

## Auth

Issue a row in `dm_exchange_tokens` (hashed Bearer secret). Abilities (JSON list):

| Ability | Endpoint |
| --- | --- |
| `have.export` / `have.import` | `GET` / `POST` `/x/disaster/api/v1/exchange/have` |
| `rm.export` / `rm.import` | `GET` / `POST` `/x/disaster/api/v1/exchange/rm` |
| `sitrep.export` / `sitrep.import` | `GET` / `POST` `/x/disaster/api/v1/exchange/sitrep` |
| `*` | All of the above |

Header: `Authorization: Bearer <plain-token>`.

Optional on RM/SitRep import: `X-Exchange-Actor-User-Id` (org user used as requester/preparer when XML omits a usable user id).

Traffic is audited in `dm_exchange_logs` (`direction` `in`|`out`, `message_type`, `payload_hash`, `status`, `meta`).

## Endpoints

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/x/disaster/api/v1/exchange/have` | Export all org medical facilities as HAVE XML |
| `POST` | `/x/disaster/api/v1/exchange/have` | Import HAVE (upsert by `facility_id`) |
| `GET` | `/x/disaster/api/v1/exchange/rm?request_id=` | Export one resource request as RM XML |
| `POST` | `/x/disaster/api/v1/exchange/rm` | Import RM (create/update request; assignments not recreated) |
| `GET` | `/x/disaster/api/v1/exchange/sitrep?sitrep_id=` | Export one SitRep as SitRep XML |
| `POST` | `/x/disaster/api/v1/exchange/sitrep` | Import SitRep (creates a new row) |

Optional query on exports: `target_organization_ids` (comma-separated or repeated) wraps the payload in EDXL-DE addressed to those org ids (`explicitAddressScheme=organization_id`).

Throttle: `60` requests / minute.

## Message types & supported fields

### EDXL-HAVE (`HospitalStatus`)

Source: `dm_medical_facilities`.

| App field | XML path |
| --- | --- |
| `facility_id` | `Hospital/OrganizationInformation/OrganizationID` |
| `name` | `OrganizationName` |
| `bed_capacity` | `HospitalBedCapacityStatus/BedCapacity/CapacityTotal` |
| `available_beds` | `CapacityAvailable` |
| `trauma_level` | `ServiceCapacity/TraumaCenterServiceTraumaLevel` |
| `generator_status` | `FacilityStatus/GeneratorStatus` |
| `resource_status` | `FacilityStatus/ResourceStatus` |

Sample: [`fixtures/edxl/have-sample.xml`](fixtures/edxl/have-sample.xml).

### EDXL-RM (`EDXLResourceMessage`)

Built from `ResourceRequestExporter` (Wave 2 lite shape).

| Status | `messageContentType` |
| --- | --- |
| `requested` | `RequestResource` |
| `approved` | `ResponseToRequestResource` |
| `matched` / `dispatched` | `CommitResource` |
| `delivered` / `cancelled` | `ReleaseResource` |

| App field | XML path |
| --- | --- |
| `dm-req-{id}` | `originatingMessageID` |
| `incident.external_id` | `incidentID` |
| resource type name/category/capability/unit | `ResourceInformation/ResourceType/*` |
| `quantity` / `priority` / `needed_by` / `status` / `notes` | `Quantity` / `Priority` / `DateTimeNeeded` / `Status` / `Notes` |
| assignments | `AssignmentInformation/Assignment` (export only) |

Sample: [`fixtures/edxl/rm-sample.xml`](fixtures/edxl/rm-sample.xml).

### EDXL-SitRep (`EDXLSitRep`)

Built from `SitRepExporter`.

| App field | XML path |
| --- | --- |
| `incident.external_id` | `incidentID` |
| title / status | `incidentTitle` / `incidentStatus` |
| preparer + time | `preparedBy` / `preparedByUserId` / `preparedAt` |
| `summary` | `Report/Summary` |
| `casualties` / `resources` / `lifelines_status` | JSON text in `Casualties` / `Resources` / `LifelinesStatus` |
| `next_steps` | `Report/NextSteps` |

Sample: [`fixtures/edxl/sitrep-sample.xml`](fixtures/edxl/sitrep-sample.xml).

### Distribution Element (`EDXLDistribution`)

Wrapper for routing: `distributionID`, `senderID`, `dateTimeSent`, `distributionType`, `explicitAddress[]`, `contentObject/xmlContent` (HAVE, RM, or SitRep). Implemented by `DistributionElement`.

## Validation

`XmlSchemaValidator` enforces structural required fields and, when present, optional lite XSD fixtures under `docs/fixtures/edxl/*-lite.xsd`.
