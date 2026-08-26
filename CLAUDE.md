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

## Live site and deployment

- Live site: `https://benyaminiceramics.omeka.net/s/CCC-1/page/home`. The installation
  lists two sites; **CCC-1 is the only one that counts** — ignore `/s/CCC`.
- Despite the domain, this is **not** omeka.net shared hosting: it is an isolated host run
  for us by the Omeka team, at `/var/www/html` on AlmaLinux 10.2 (Apache + PHP-FPM 8.3,
  MySQL on localhost, files served from S3 via the S3FileStore module). Gil has SSH with
  full command access as an unprivileged user; root-level changes (PHP extensions, ini
  settings, file ownership outside the theme) are requested from the Omeka team.
- Deployment is `git pull` on the host, so pushing to `master` ships.

## Performance probes

`helper/PerfProbe.php` is the theme's own request profiler, built for optimization.md and
kept afterwards. **It stays in the theme deliberately** — it is inert without the token, it
costs nothing when unarmed, and it is the only way to see where a page's time goes on this
host. Read `optimization.md` before using it: that file holds every measurement taken so
far, what turned out to be true, and what turned out to be an artefact.

It is inert unless both a stage param and the shared token are present:

| Page | URL |
|---|---|
| Home | `…/page/home?hgdebug=N&probe=TOKEN` |
| Search results | `…/item?types[]=17&fsdebug=N&probe=TOKEN` |
| Item show (any of the three partials) | `…/item/<id>?irdebug=N&probe=TOKEN` |

`TOKEN` is the `PerfProbe::TOKEN` constant — the only copy; rotate by editing it. Without
it the page renders normally, which matters because the dump reports the database host and
name and the PHP configuration.

`N` is the stage to stop after, numbered per call site. Home: 1 = image pool, 2 = hero,
3 = discover tiles, 4 = selected-items fragment (add `&selected_items=1`). Search:
1 = parseParams, 2 = computeResultIds, 3 = pageResults, 4 = decadeFacets, 5 = domainFacets,
6 = eventTypeFacets (6 runs everything).

`irdebug` is shared by all three item-show partials — a request renders exactly one of
them, so there is no ambiguity, but the stage numbers mean different things per template:

| Partial | Templates | Stages |
|---|---|---|
| `item-person-org.phtml` | 17, 18 | 1 = eventsByRole, 2 = events, 3 = documents, 4 = galleryTiles |
| `item-event.phtml` | 16 | 1 = parentResource, 2 = creatorGroups, 3 = relatedDocsAndPhotos |
| `item-document.phtml` | 15, 20 | 1 = parentResource, 2 = creatorGroups, 3 = mediaTiles |

Stage 2 is the useful one in each: on a Person/Org it brackets the reverse role-property
traversal, and on an Event/Document it brackets `creatorGroups()`, whose cost is the
lazy-loading of each credited resource rather than queries of its own.

The dump prints each stage twice — template-relative and request-relative — so bootstrap
time before the template appears as its own line. It installs a Doctrine `DebugStack` SQL
logger for per-stage query counts and timings, lists the slowest and most-repeated
statements, and prints an environment block (Xdebug / OPcache / APCu state, raw ini values,
Doctrine cache driver classes, `files/` writability, DB round-trip latency).

Add `&probeexplain=N` to also `EXPLAIN` the N slowest logged `SELECT`s, printing each
statement in full with its bound parameters and MySQL's plan. Off by default; the ordinary
report truncates statements at 150 characters, which is fine for spotting an N+1 but not for
diagnosing one query.

Helpers that keep their own marks (`HomeGraph`) expose `marks()` and `startedAt()` and are
merged into the timeline via `$probe->attach($helper)`. To instrument a new page: call
`$this->PerfProbe()->begin('<param>')` at the top of the template, then `$probe->stage(N,
'label', $detail)` at each point of interest — `stage()` dumps and exits when `N` matches
the requested level, so numbering runs in template order.

### Running it

**Measure from the host over loopback, never from Windows** — WAN and TLS add about a
second and hide what changed. Plain `http://127.0.0.1` 301-redirects to HTTPS, so resolve
the real hostname to loopback instead:

```
curl -sk --max-time 120 -w '\n== total %{time_total}s ==\n' \
  --resolve benyaminiceramics.omeka.net:443:127.0.0.1 \
  'https://benyaminiceramics.omeka.net/s/CCC-1/page/home?hgdebug=3&probe=TOKEN'
```

Pipe through `sed -n '1,12p'` rather than `head` if you only want the timing table; `head`
closes the pipe under curl. Do not wrap the call in `sleep`: the dump only arrives when the
page finishes, so a foreground sleep looks like a hang.

### Reading the output

Columns are: `req` (seconds since `REQUEST_TIME_FLOAT`), `tpl` (since template start), `+q`
and `q` (queries in this stage / cumulative), `+sql` and `sql` (SQL seconds, same). The
first row is framework bootstrap — everything before the template runs. A stage that is slow
with few queries and little SQL time is PHP work, usually class loading.

**Check the environment block before trusting any number.** Two traps cost real time during
Phase 0–2: measurements taken with no opcode cache are not comparable to current ones (they
were ~20× slower at bootstrap), and measurements taken while the host is busy with something
else inflate SQL times several-fold. If DB round-trip max is far above the median, the box is
contended — re-measure later rather than optimising against it.

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

## Item show pages

`view/omeka/site/item/show.phtml` routes by resource template id to a bespoke full-page
partial; anything not in the table below falls through to the block-based rendering
configured in `config/theme.ini`.

| Template | Partial | Design |
|---|---|---|
| People (17), Organizations (18) | `view/common/item-person-org.phtml` | `Designs/item.html` |
| Documents (15), Photographs (20) | `view/common/item-document.phtml` | `Designs/item-child.html` |
| Events (16) | `view/common/item-event.phtml` | `Designs/item-child.html` |

All three share the same chrome: a back-link parent bar, a hero, an expandable details
drawer, a results grid with a grid/list switcher, and the `commentForm` feedback card.
What fills the grid differs by type — Person/Org get their related Events, Documents and
installation photos; a Document gets **its own media files** as lightbox tiles; an Event
gets the Documents and Photographs that reference it.

The item-child pages set the body class to `item-child` **only**. Adding `item` would pull
in `.item .item-hero-grid` and `.item .hero-title` from `site.css`, which restyle the
full-size hero and must not apply to the condensed layout.

All the CSS these pages need already exists in `asset/css/site.css` (`.item-parent-bar`,
`.item-hero-grid.condensed`, `.item-child .hero-file-link`, `.creators-link-cloud`,
`.gallery-item`, …) and all the JS in `asset/js/site.js` (`setupItemDetails`,
`setupViewSwitcher`, `setupLightbox`).

**Not implemented:** the newer modal feedback form (`.item-feedback-open-btn` /
`#item-feedback-modal`) from the design has no CSS in `site.css` and no JS in `site.js`;
the pages use the inline `commentForm` card instead.

`helper/ItemRelations.php` supplies the data. Beyond the Person/Org methods it exposes:
`parentResource()` (the parent-bar target), `creatorGroups()` (**Agents** grouped by
creator-role property), `mediaTiles()` (one resource's own files as gallery tiles),
`relatedDocsAndPhotos()`, and `primaryFileLink()` (the hero "open file" target, or the
YouTube link for a video Document).

**The creators cloud carries Organizations as well as People.** The role properties in
`ItemRelations::ROLE_PROPS` take an *Agent* — template 17 or 18 — so `creatorGroups()`
returns both, People first then Orgs within each role group, with no visual distinction.
Its group key is therefore **`agents`**, not `people`; both call sites
(`item-event.phtml`, `item-document.phtml`) iterate `$group['agents']`. Symmetrically,
`eventsByRole()` groups an Organization's Events by role the way a Person's already were,
with the `dcterms:relation` (affiliation) Events kept as one leading *unlabeled* group and
never duplicated into a role group. See `Relationships.md` §2–§4.

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
