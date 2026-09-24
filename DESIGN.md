# Design

<!-- impeccable:design-schema 1 -->

## World

**shadcn Operate** — Laravel React starter kit baseline: shadcn/ui **new-york** style, **neutral** tokens, Instrument Sans. Dark/light via `.dark` on `html`.

## Mode

Operate

## Tokens

- Source of truth: `resources/css/app.css` (starter-kit CSS variables)
- Surfaces: `background`, `card`, `muted`, `sidebar-*`
- Primary follows starter-kit neutral (near-black / near-white), not Spatial cyan
- Icons: Lucide

## Chrome

- App shell: shadcn `Sidebar` + GIS nav from `ConsoleProps` (`Workspace` + collapsible `Organize`)
- Flash alerts in the main content column
- Theme toggle in header and user menu (`localStorage: console.theme`)

## Tables

List tables use `DataTable` / helpers from `resources/js/components/gis.tsx` on shadcn `Table`.

## Primitives

- Vendored under `resources/js/components/ui/` (refresh with `npx shadcn@latest add …`)
- Domain adapters: `resources/js/components/gis.tsx`

## Archive

Pre-migration Ninna/Spatial UI: `resources/js/_archive/ninna-spatial/` (reference only; do not import).

## Out of scope / deferred

- Map panels still use some Bootstrap form controls inside floating editors; chrome (topbar, dock, search, status) is on shadcn tokens
- Full Bootstrap removal inside layer/style/analysis panels is a follow-up

## Map workspace

Floating Operate chrome over OpenLayers: card surfaces, hairline borders, Instrument Sans, Lucide icons. Builder and viewer share `MapWorkspace`.