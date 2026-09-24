# Design

<!-- impeccable:design-schema 1 -->

## World

**Spatial Intelligence Operate** — Ninna UI components on a Stitch-aligned `data-theme="spatial"` token set. Dark slate/cyan mission-control for GIS editors; not stock ocean SaaS demos.

## Mode

Operate

## Tokens

- Theme attribute: `html[data-theme="spatial"]` with `.dark` / `.light`
- Surfaces: `#0b1326` canvas family in dark; primary `#4cd7f6`; secondary `#89ceff`; accent/warning `#ffb95f`
- Type: Inter (UI), JetBrains Mono (counts, coordinates, tabular)
- Density: compact 4–8px rhythm; 13px body; soft 2–4px radii
- Aliases `canvas` / `panel` / `line` / `copy` / `cyan` map 1:1 onto spatial Ninna vars — no second palette

## Chrome

- Workspace nav (Dashboard, Imports, Layers, Maps, Projects) always visible
- Organize group collapsed by default (`localStorage: console.organizeOpen`)
- Flash lives in `AppLayout` once for all authenticated pages
- Dashboard leads with import → publish → map next step

## Tables

List and attribute tables use `DataTable` from `Components/ui.jsx`:

- Uppercase compact headers (`thClass`)
- Row cells via `tdClass` / `tdMuted` / `tdMono`
- Hairline `border-border` only
- Counts, sizes, roles, coordinates in JetBrains Mono
- Every list table includes a header row

## Out of scope / deprecated

- `resources/js/console/` + `layouts/app|console.blade.php` are legacy; Inertia `app.blade.php` is the product shell
