# Plugin host security review (A5 / #57)

Reviewed: super-admin vs org-admin, `plugin:{key}` gating, org isolation on jobs, and `organization_plugins.settings` encryption.

## Fixed in this pass

| Issue | Fix |
| --- | --- |
| Alerts still sent after org disable | `AlertDispatcher` only delivers for `enabledOrganizations('early-warning')`; `dispatchOne` re-checks `isEnabledFor` |
| Manifest permissions not enforced | `PluginManager::registerPermissionGates()` maps `*.manage` → org admin, `*.view` → org member; EW mutations call `authorize('early-warning.manage')` |
| Settings plaintext at rest | `OrganizationPlugin.settings` cast `encrypted:array` + migration to encrypt existing rows |

## Remaining / deferred

| Issue | Notes |
| --- | --- |
| ADR D14 fail-closed tenancy (`BelongsToOrganization` / `CurrentOrganization`) | Architectural follow-up; jobs still manually filter by org / enabled list |
| `/platform/extensions` super-admin UI | Capability gap vs ADR §5; gates already allow super-admin cross-org toggle |
| Citizen report photos on `public` disk | Prefer private disk + signed URLs |
| ConsoleModules fail-open when a gate is missing | Kept for fixture plugins that declare item permissions without registering gates; EW permissions are registered |

## Controls verified

- `plugins.install` = super admin; `plugins.toggle` = org admin (own org) or super admin
- `EnsurePluginEnabled` 404 when not installed/enabled for the user’s org (no super-admin bypass on `/x/...`)
- Public EW tokens hashed; feed/report re-check install + enable
- ExtensionController refuses non-admins (`ExtensionControllerTest`)
