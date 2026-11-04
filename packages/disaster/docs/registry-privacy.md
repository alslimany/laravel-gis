# Registry privacy notes (shelter / beneficiaries / missing persons)

**Legal / ethical review is required before collecting production PII.**

This module stores people-affected data for operational response. Fields and defaults are intentionally minimal and reversible.

## Design principles

- Prefer opaque codes (`household_code`, `case_code`, `contact_ref`, `reporter_ref`) over real names or phone numbers.
- Missing-person `given_name` / `family_name` are staff-only; models hide them from default serialization and public endpoints never return them.
- Beneficiary `contact_ref` is hidden by default and omitted from API presentations unless an explicit privileged path asks for it.
- Every personal-record read, export, or update writes an append-only `dm_registry_access_log` row.
- `retained_until` drives `disaster:prune-registry` (scheduled daily in the disaster service provider). Rows without a retention date are not auto-deleted.
- Public surfaces (`/dm/public/{token}/…`) expose **aggregate shelter occupancy only** (counts / site labels) — never beneficiaries, names, notes, or contact refs.

## Permission

`disaster.registry` is gated to authenticated org users who are admin **or** already pass `disaster.manage`. Field-level ACL can tighten later without changing the public contract.

## Before production

1. Confirm lawful basis and retention periods with counsel / data-protection officer.
2. Review whether any collected field must be encrypted-at-rest.
3. Confirm export and matching suggestion flows meet local reunification / missing-persons policy.
4. Train operators not to put raw phone numbers or national IDs into free-text `notes`.
