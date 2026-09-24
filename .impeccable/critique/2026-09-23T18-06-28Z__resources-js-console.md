---
target: /impeccable critique
total_score: 18
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
target_identity: "file:/Users/abdullah/Herd/gis-app/resources/js/console"
timestamp: 2026-09-23T18-06-28Z
slug: resources-js-console
closed: true
---
Method: dual-agent (A: c9c5d3db-3522-4a46-ad1e-13c766a035b1 · B: d8b0a7ed-2754-488f-9df7-86ae8dc96e6c)

Target: the console shell and dashboard (`resources/js/console`), the editor's home. The map builder was left out of this run.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Published/Draft pills on the desk; no import or job status on the home surface |
| 2 | Match System / Real World | 3 | GIS nouns are right; "Dashboard" beside "Dashboards" is not |
| 3 | User Control and Freedom | 2 | Mobile nav closes; project rows are not links; logout is icon-only with no confirm |
| 4 | Consistency and Standards | 2 | React desk and Bootstrap Layers/Imports/login read as two products |
| 5 | Error Prevention | 1 | Delete sits on hosted rows with no shared confirm pattern; Register stays open on a one-org console |
| 6 | Recognition Rather Than Recall | 2 | Expanded nav is labeled; collapsed sidebar is icon-only; Dashboard/Dashboards forces recall |
| 7 | Flexibility and Efficiency | 1 | No shortcuts, bulk actions, or command palette |
| 8 | Aesthetic and Minimalist Design | 2 | Calm tokens, then a flat 12-item nav and two "Open map builder" buttons |
| 9 | Error Recovery | 2 | Empty states point at import and the map builder; login recovery is stock Laravel copy |
| 10 | Help and Documentation | 1 | No in-shell help or loop guidance; catalog jump is browse, not help |
| **Total** | | **18/40** | **Poor** |

## Design Specificity Verdict

**LLM assessment:** Partially grounded, not yet this product. Labels earn their place: Data imports, Layers, Geometry, Published/Draft, "Open map builder," and the dashboard line that layers, maps, and projects sit here before you open the map. Composition does not. A dark flat sidebar, a KPI strip, and two panels could be a CRM. The guest header shows the framework name Laravel. The compass mark is a generic wayfinding icon. Hosted pages (`resources/views/layers/index.blade.php`, `resources/views/imports/index.blade.php`, `resources/views/auth/login.blade.php`) are still Bootstrap CRUD inside the React chrome. Missed character: no map thumbnail, extent, or publish pipeline in the shell, no visual split between the editor loop and admin tools, and Dashboard vs Dashboards as colliding names.

**Deterministic scan:** `impeccable detect` on `resources/js/console` exited 0 with **0 findings**. The React shell is clean of the mechanical anti-patterns the CLI checks. That agrees with the review on craft of `console.css` (IBM Plex, ink/paper/terra, focus-visible, reduced motion). It does not agree that the product is fine: the CLI cannot see a 12-item nav or a Bootstrap page hosted in a hidden blade slot.

Browser injection on `http://127.0.0.1:8080/login` did run and logged three hits the CLI missed, because they live in the hosted Blade form and Bootstrap CSS, not in the JSX:

- `cramped-padding` on the password-reset link (`btn btn-link` "Forgot Your Password?" in `login.blade.php`): real. 5.6px horizontal padding on 14.4px text.
- `layout-transition` (`transition: margin`): low-signal Bootstrap default, not a console motion choice.
- `dark-glow` (`#ffba00` zero-offset shadow): false positive for this product. Console terra is `#0f766e`. The amber glow is not in `console.css`; it reads as autofill or Bootstrap, not the brand.

The authenticated dashboard was not injected (the detector's own tab was logged out). No durable overlay was left: the live server on port 8400 was stopped after the login scan.

## Overall Impression

The desk knows who it is for. The shell does not know what to hide. An editor lands on the organization name and an inventory that points at import and the map builder, which is the right Operate opening. Then twelve equal nav items, a second map-builder button, and a Bootstrap page on the next click erase that focus. Cognitive load is high: 7 of 8 checklist items fail. The single biggest opportunity is to make the sidebar teach the loop (import, publish, map) and let admin tools wait.

## What's Working

1. **Organization-first home.** `DashboardHome` titles the page with the organization name and inventory copy, not a generic welcome.
2. **A real token system.** `console.css` (IBM Plex, `--color-ink` / `--color-paper` / `--color-terra`, `:focus-visible`, `prefers-reduced-motion` on `.desk-intro`) is more deliberate than a default admin kit. Sidebar collapse persists in `localStorage`.
3. **Empty states name the job.** "Import a dataset" and "Compose one in the map builder" point at the success path instead of a blank table.

## Priority Issues

### [P1] Flat 12-item sidebar with no loop grouping

- **Why it matters:** For an admin in an organization, `ConsoleProps::nav()` lists Dashboard, Projects, Data imports, Layers, Maps, Catalog, Dashboards, Forms, Groups, API tokens, Organization, and Users as one list. The import → layer → map path is buried. First paint also stacks Import data, two Open map builder actions, Search catalog, count links, and "All layers / maps / projects."
- **Fix:** Split a Workspace group (Dashboard, Projects, Data imports, Layers, Maps) from an Organize/Admin group that stays closed until asked. Keep admin items off non-admin nav, which the Users item already does.
- **Suggested command:** `/impeccable distill`

### [P1] The shell and the hosted pages are two products

- **Why it matters:** The desk uses `.desk` tables and pills. Layers, Imports, and Login drop into Bootstrap `.card`, `.btn`, and Font Awesome. The desk says Draft; the Layers index says Not Published. Trust breaks in the middle of the editor's loop. The detector's cramped "Forgot Your Password?" link is the same split, on the guest form.
- **Fix:** Restyle hosted index and auth pages in the desk language (type, pills, buttons, spacing), including the password-reset link padding.
- **Suggested command:** `/impeccable adapt`

### [P1] Guest identity is still the framework

- **Why it matters:** `GuestShell` prints `brand.name`, which is currently Laravel, and always offers Register next to Log in, including on `/login` where Log in is redundant. A one-organization console should not look like a public Laravel starter.
- **Fix:** Default the guest wordmark to the organization or "GIS Console." Remove the in-page Log in link on the login screen. Gate Register unless this deployment is actually open signup.
- **Suggested command:** `/impeccable onboard`

### [P2] Two primary buttons and a search that is not search

- **Why it matters:** "Open map builder" appears in the top bar and in `.desk-actions`. `catalog-jump` looks like search and navigates to the catalog. Extra choices, wrong expectation.
- **Fix:** One map-builder button. Relabel the field "Browse catalog," or make it actually find layers and maps.
- **Suggested command:** `/impeccable quieter`

### [P2] Narrow viewports clip status, and logout has no name

- **Why it matters:** Around 390px the Published pill truncates ("Publishe"). Logout is `title="Log out"` with no `aria-label`, so the exit is a tooltip. Collapsed nav is the same pattern: icon plus `title` only.
- **Fix:** Stack layer rows or drop secondary columns under a breakpoint. Give logout and collapsed items an accessible name.
- **Suggested command:** `/impeccable harden`

## Persona Red Flags

**Alex (power user):** No keyboard shortcuts, no bulk select on the desk or the Layers index, and a 12-item scan before any work. The power affordance is a duplicated map-builder button. Collapsed icons slow a second pass through the same nav.

**Sam (keyboard and low vision):** Logout and collapsed nav depend on `title` tooltips. Groups (`UsersRound`) and Users (`Users`) are easy to confuse by shape alone. Focus rings exist in `console.css`, then Blade icon buttons pack tight targets around them. Mobile truncation clips "Published," so status becomes a partial word.

**GIS editor:** The loop in the product brief never appears as a pipeline. The desk does not surface an unfinished import or an unpublished layer. Project rows are not links. Analysis is absent from the shell. "New Layer" on the Layers page and "Import data" on the desk fork the start of the same job. The nav works only if the editor already knows the order.

## Minor Observations

- Storage reads `124.00 KB` (false precision).
- Geometry shows an em dash on published layers, which looks like missing data.
- Live map title typo: "Ly Boundries Map."
- `--color-terra` is teal `#0f766e`; the token name will mislead the next person who themes it.
- GuestShell's `routes?.login` branch is a no-op (`null : null`).

## Questions to Consider

- If the sidebar showed only the five loop stops until someone asked for admin, would anyone miss Catalog on day one?
- Should the first viewport be a pipeline (Import → Publish → Map) instead of four inventory tiles, one of them Storage?
- Why does the product say "Open map builder" twice before it ever mentions analysis, the stated end of the job?
