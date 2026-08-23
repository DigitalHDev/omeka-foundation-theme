# Foundation Theme (Dev) - Omeka S 4.2

## Project Structure

This is a customized version of the Omeka S Foundation theme ("Foundation-Dev", v1.5.3). It is a PHP theme built on the ZURB Foundation CSS framework.

### Repo setup

- **Theme repo** (this directory): `C:\Users\gilsh\source\repos\omeka-foundation-theme - Dev`
  Contains the theme files that get deployed to an Omeka S installation's `themes/` directory.
- **`Relationships.md`** (this directory): the data model — resource templates, the properties
  used as edges, edge directions, standard traversals, and known inconsistencies. **Read it
  before writing or changing any code that walks between item types.**
- **Omeka S core** (read-only reference): `C:\Users\gilsh\source\repos\omeka-s`
  The full Omeka S 4.2 source. Use this to look up core view helpers, controllers, API behavior, default templates, and resource page block logic. Do not modify files here.
- **AdvancedSearch module** (read-only reference): `C:\Users\gilsh\source\repos\Omeka-S-module-AdvancedSearch`
  Installed on the live site. Adds extended property-query types used by the theme's search,
  e.g. `in` (contains) and `yrgte`/`yrlte` (year range), available on normal item API queries.
- **Reference module** (read-only reference): `C:\Users\gilsh\source\repos\Omeka-S-module-Reference`
  Installed on the live site. Used for distinct-value aggregation (facet/checkbox lists) via the
  `references()` view helper: `$view->references()->list($fields, $query, $options)`.

### Key directories

- `view/` — Theme view templates (`.phtml` files). Overrides core templates from `omeka-s/application/view/`.
- `view/common/resource-page-block-layout/` — Custom resource page blocks (tab navigation, custom callouts).
- `view/common/block-template/` — Block templates for browse preview, assets, list-of-sites, etc.
- `asset/css/` — Compiled CSS stylesheets (default, inkwell, revolution, seafoam).
- `asset/scss/` — SCSS source files. Compiled via gulp (`gulpfile.js`).
- `asset/js/` — JavaScript files. `site.js` drives the active design chrome (search panel,
  hamburger, filter drawer, view switcher, lightbox); `show.js` / `browse.js` are legacy.
- `helper/` — PHP view helper classes (`TabManager.php`, `SecondDegreeResources.php`,
  `ItemRelations.php`, `HomeGraph.php`, `ComposeResourceTitle.php`, `FacetedSearch.php`).
- `config/theme.ini` — Theme configuration: metadata, form elements for admin settings, resource page regions/blocks, block templates, page templates.

### Custom additions beyond upstream Foundation theme

- **TabManager** helper — manages tab-based navigation on resource show pages.
- **SecondDegreeResources** helper — fetches and displays resources connected through intermediate resources (second-degree connections).
- Custom resource page block layouts: `tab-navigation`, `customCallout`, `customCalloutFrame`.
- Custom block templates for browse preview (grid/list/toggle), assets (card), list-of-sites (card), page title (accent).
- Multiple resource page regions: `full_width_main`, `main`, `right` sidebar, `left` sidebar.

### Design considerations

- All pages and views should be mobile firendly

## Local tooling

- **PHP 8.3** (matches the omeka.net host) is installed but not on PATH. Full
  path: `C:\Users\gilsh\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`.
  Use it for syntax linting, e.g. `& "<that path>" -l <file.phtml>`. Note: this is
  CLI-only — there is no local Omeka install or database, so the theme cannot be
  rendered locally; render/test by deploying into the VM sandbox site.

## Conventions

- Templates use PHP short echo tags (`<?= ?>`).
- View helpers are called via `$this->helperName()` in `.phtml` files.
- Theme settings are accessed via `$this->themeSetting('setting_name')`.
- CSS: the **legacy** theme stylesheets (`default`, `inkwell`, `revolution`, `seafoam`) are
  compiled from SCSS via gulp — edit `.scss`, not those `.css`. The **new design system** lives
  in `asset/css/site.css`, which is **hand-maintained** (ported from `designs/*.css`, no SCSS
  source) — edit it directly. `site.css` is appended last in `layout.phtml` so it wins.
- The theme targets Omeka S ^4.1.0 (currently running 4.2).

## Documentation policy

When a change is **major** — a new view helper or feature, an architectural decision, a new
param/URL scheme, a new module dependency, or a change to established traversal/query rules —
**flag that documentation is needed and update it in the same change.** At minimum: add or
update the relevant section of this `CLAUDE.md`, and update docblocks on any new/changed
helper. Do not leave a major change documented only in commit messages or chat. Trivial
changes (copy tweaks, small style/markup fixes, bug fixes with no interface change) do not
require this.

Anything that touches the **data model or graph traversal** — a new edge, a new property id, a
changed direction, a new item type, or a newly discovered inconsistency — must also be recorded
in `Relationships.md` in the same change.

## SecondDegreeResources usage policy

The edges themselves are documented in `Relationships.md`; this section covers which code may
implement them.

The `SecondDegreeResources` helper encapsulates tested traversal logic for the
site's Event-centric graph (Document/Photograph → Event → Organization, and
Event → Person via creator roles). On all pages **other than the home page**,
use this helper for second-degree connections. Its logic has been tested in
production; **any change to its behavior must be discussed before implementation.**

The home page may use a leaner, purpose-built lookup for performance (it resolves
many random items per request), but it should **reuse code/logic from
`SecondDegreeResources` where possible** rather than diverging. Do not silently
fork its traversal rules.

## Faceted search / results page

The site search and its results page are implemented **theme-side**, deliberately **not** using
the AdvancedSearch module's facets. Columns for *domain* and *event type* hold values that live
on Events but must also filter Documents by walking the graph (Document → its Event → the
Event's domain/type), which AdvancedSearch/Reference cannot facet across. Per-checkbox counts
are intentionally omitted.

**Pieces:**
- `helper/FacetedSearch.php` — all query logic. Parses the request, does a *resolve-then-union*
  of id-only (scalar) item queries per selected type, returns the paged results plus the four
  facet value lists. It **reuses `ItemRelations` constants/traversal rules and must not fork
  them** (same policy as SecondDegreeResources below). Distinct facet values come from the
  Reference module.
- `view/common/search-results.phtml` — the results layout (4-column filter drawer + result grid
  + pager). Rendered by a branch in `view/omeka/site/item/browse.phtml` when search params are
  present (item-set / plain browse are unaffected).
- `view/common/site-header.phtml` — the search panel form and hamburger item-type links feed the
  results page.
- `asset/js/site.js` → `setupSearchFilters()` — filter-drawer toggle and gating of the domain /
  event-type columns.

**Param scheme** (on the item browse route): `q` (title *contains*, property `dcterms:title`
type `in`), `types[]` (template ids 17/16/15/18 = People/Events/Documents/Organizations),
`decades[]` (start year), `domains[]` (`cidoc:P19i_was_made_for`, custom vocab #9), `etypes[]`
(`dcterms:type`). Domain/event-type columns are active only when Events(16) or Documents(15) are
selected; a Document is matched through the Event it relates to. Decade for People/Orgs is
derived from their related Events. Event *domain* lives in `cidoc:P19i_was_made_for` (only on
events whose `dcterms:type` = אירוע); event *type* is in `dcterms:type`.

**Open items:** merged-list sort is `dcterms:date` asc (undated resources sort first under
MySQL); Photographs (template 20) are currently folded into Documents rather than shown as their
own checkbox.

## Looking up Omeka S internals

When you need to understand how a core feature works (view helpers, API queries, resource representations, block layouts), look in the omeka-s repo:

- View helpers: `omeka-s/application/src/View/Helper/`
- Default site templates: `omeka-s/application/view/omeka/site/`
- Common partials: `omeka-s/application/view/common/`
- API adapters: `omeka-s/application/src/Api/Adapter/`
- Entity representations: `omeka-s/application/src/Api/Representation/`
- Block layouts: `omeka-s/application/src/Site/BlockLayout/`
- Resource page block layouts: `omeka-s/application/src/Site/ResourcePageBlockLayout/`
