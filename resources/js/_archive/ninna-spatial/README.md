# Ninna / Spatial UI archive

Snapshot of the pre-shadcn GIS console UI (Ninna + `data-theme="spatial"`).

**Do not import from this folder in live app code.** Use it only as a reference when rebuilding pages and adapters on shadcn.

## Contents

| Path | What |
|------|------|
| `Pages/` | Inertia page JSX |
| `Layouts/` | AppLayout, GuestLayout, PublicLayout |
| `Components/` | Shared wrappers (`ui.jsx`, etc.) |
| `console/` | Deprecated Blade-hosted console SPA |
| `css/` | `app.css` + `spatial-theme.css` at archive time |
| `DESIGN.ninna.md` | Design world before shadcn migration |

## Rebuild rule

Copy **behavior** (props, routes, actions, copy) from archived files. Implement visuals with live `resources/js/components/ui/*` (shadcn) and GIS adapters.
