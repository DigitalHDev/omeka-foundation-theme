# Optimization plan: cut home + search page load from 8–10s

## Context / measurements so far

Live site: isolated hosting, controlled by us or changeable on request.
Collection: several hundred items per template; People (17) > 1,100. Total ~3–4k
items. Maximum expected growth: 2×.

Home page `?hgdebug=N` marks (template-relative):

| stage | cumulative |
|---|---|
| imagePool windows read (2 count + 2 windowed searches, 60 items) | 0.469s |
| imagePool thumbnails checked (60 × `thumbnailDisplayUrl`) | 1.861s |
| stopped after pool | 1.850s |
| stopped after hero | 1.869s |
| stopped after discover tiles (4) | 2.534s |

TTFB is ~6s, so **~3.5s is unaccounted for** — `$hgStart` is set *inside* the
template, so bootstrap, module loading, routing and layout render all fall
outside the probe.

The async `?selected_items=1` fragment (`orgDescendantPool`) is the "2–3s for
items to arrive": ~50 scalar id queries + 3 hydrate chunks + ~32 thumbnail
lookups.

## Root-cause hypothesis (to confirm in Phase 0)

~23ms for a trivial indexed query against a 4k-item database is anomalous.
Leading suspects, in order:

1. **Remote / high-latency MySQL** — 23ms looks like network RTT. If so, the fix
   is round-trip reduction and co-location, not query tuning.
2. **Doctrine metadata/proxy cache falling back to ArrayCache** (APCu absent) —
   re-parses every entity annotation on every request. A classic multi-second
   bootstrap cost, and would explain the ~3.5s baseline.
3. **Xdebug loaded in the PHP-FPM pool** — 2–5× blanket slowdown.
4. **OPcache off or undersized** — Omeka + Doctrine is thousands of files.

## Known code-level defects (independent of the above)

- **N+1 thumbnail lookups.** `ItemRepresentation::primaryMedia()`
  (`omeka-s/application/src/Api/Representation/ItemRepresentation.php:82`) issues
  a separate `findOneBy` per item on top of lazy-loading `getMedia()`. 60 pool
  items ≈ 120 queries. `HomeGraph::imagePool()` eagerly checks all 60 even though
  only ~4 are ever used by the discover tiles.
- **Search facet lists.** `decadeFacets()` / `domainFacets()` /
  `eventTypeFacets()` fire three Reference-module `GROUP BY` aggregations on
  every results page load, before any results render. Their values change only
  when data changes.
- **Search resolve-then-union.** `computeResultIds()` pulls every matching id
  into PHP, then passes the whole list back as `id => [...]`. At ~3k ids this is
  meaningful but not catastrophic — lower priority than first assumed.

## Phases

### Phase 0 — Measure (blocks everything else)

**0.1** Extend the home probe in `view/common/page-template/home.phtml`:

- capture `$reqStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? $hgStart`
- print each mark as both template-relative and request-relative
- print a **bootstrap** line = `$hgStart - $reqStart` — this is the key number
- install a Doctrine `DebugStack` SQL logger at template start; print total query
  count and summed query time at the end of the dump
- print `memory_get_peak_usage(true)`
- the entity manager is reachable via
  `$this->getHelperPluginManager()->getServiceLocator()->get('Omeka\EntityManager')`,
  then `$em->getConnection()->getConfiguration()->setSQLLogger(...)`

**0.2** Add an equivalent `?fsdebug=N` probe to the search path, with marks after
`parseParams`, `computeResultIds`, `pageResults`, and each of the three facet
lists. Same request-relative + query-count output.

**0.3** Environment checks on the host:

- `php -i` / phpinfo for the FPM pool — Xdebug loaded? OPcache enabled, and its
  hit rate? APCu present?
- Omeka's Doctrine cache config (`application/config/module.config.php`) — confirm
  it is not ArrayCache
- DB round-trip latency — is MySQL on localhost or remote? Time a trivial
  `SELECT 1` loop
- MySQL slow query log at a 0.1s threshold, then load both pages

> **Decision gate:** if bootstrap is ~3.5s, Phase 1 outranks all query work.

### Phase 0 results (measured 2026-08-25)

**0.1 / 0.2** implemented as `helper/PerfProbe.php`, wired into
`view/common/page-template/home.phtml` (`?hgdebug=N&probe=TOKEN`) and the search branch of
`view/omeka/site/item/browse.phtml` (`?fsdebug=N&probe=TOKEN`). Documented in `CLAUDE.md`.

**0.3** answered over SSH on the host, plus curl timings from the host over loopback.

Environment (`/var/www/html`, AlmaLinux 10.2, Apache + PHP-FPM 8.3.31 pool `www` as
`apache`, 21 of 22 modules active):

| Check | Result |
|---|---|
| OPcache | **Not installed.** `[Zend Modules]` empty, no `php-opcache` package, no `zend_extension` in `/etc/php.ini` |
| APCu | **Not installed**, so `EntityManagerFactory` falls back to `ArrayCache` and Doctrine rebuilds entity metadata from annotations every request |
| Xdebug | Not installed |
| MySQL | `localhost`, listening on 3306 on the same host |
| `files/` | `root:root`, not writable by `apache` — Phase 4 must use the `Settings` table |
| `logs/*.log` | `root:root`, unwritable; logging enabled in `local.config.php` but silently discarded since 2025-12-04 |
| S3FileStore | Active file store; `getUri()` is `getObjectUrl()`, pure local string building — **no network I/O in the thumbnail path** |

Timings, server-side over loopback:

| Layer | Time |
|---|---|
| Omeka bootstrap (404 page: full framework, no theme render) | **1.77s** (±0.02s over 3 runs) |
| Site list render on top of bootstrap | +0.04s |
| Home page total | 4.37–5.20s |
| **Theme's own work** | **2.6–3.4s** |
| Search results (`?types[]=17`), external 4.4s less ~1s network | ~3.4s server-side |

Root-cause hypotheses: **#1 remote MySQL — dead. #2 Doctrine ArrayCache — confirmed.
#3 Xdebug — dead. #4 OPcache — confirmed, and absent rather than merely disabled.**

Consequences for the rest of this plan:

- **Phase 1 is a request to the Omeka team** (`dnf install php-opcache php-pecl-apcu`, ini
  settings with `opcache.save_comments=1` preserved for Doctrine annotations, restart
  php-fpm, plus `chown apache:apache logs/*.log`). It targets the 1.77s every page pays and
  needs root, which we do not have.
- **Phase 2.1 stands as written** — the ~23ms per `thumbnailDisplayUrl()` is the N+1
  `primaryMedia()` database lookup, not S3 network latency.
- **Phase 4 storage is decided:** the `Settings` table, because `files/` is not writable by
  the web user.
- Bootstrap is stable (26ms spread) while the home page jitters by ~830ms across identical
  requests, so the variability lives in the theme's query/thumbnail work.

### Phase 1 — Environment (parallel with Phases 2–3 once Phase 0 reports)

Driven by the 0.3 findings. Likely: enable APCu so Doctrine caches metadata and
proxies, remove Xdebug from the web pool, size OPcache, co-locate or pool the DB
connection.

### Phase 2 — Home page round-trip reduction

**2.1** Stop eagerly checking 60 thumbnails in `HomeGraph::imagePool()`. The pool
is already filtered by `has_media=1`, and only ~4 items ever become tiles. Move
the thumbnail check into `HomeGraph::discoverTile()` so it validates the
candidate it picks and skips to the next if there is no derivative. Same
guarantee ("a tile always has an image"), ~60 lookups down to ~4–10. Expected
saving ~1.3s, no behaviour change.

**2.2** Same treatment in `HomeGraph::hydrateInOrder()` for the async fragment.

**2.3** Reduce the id-query count in `orgDescendantPool()` — currently up to ~50
single-target reverse lookups. Batch the per-event Document/Photograph lookups
into one OR'd query per org instead of two per event.

### Phase 3 — Search page

**3.1** Cache the three Reference facet lists (mechanism in Phase 4).

**3.2** Fast path in `FacetedSearch::computeResultIds()`: when no decade, domain
or event type is selected, the union provably reduces to a single query
`resource_template_id => [15,16,17,18]` plus the same title clause. Bypass the
union and let `pageResults()` issue one paged query. This also collapses the
Events+Documents-only + decades case. Behaviour-identical — sorting and paging
are already DB-side.

Keep the existing union intact as the fallback for the derived cases (People/Org
decade-via-Events, Document domain-via-Event). Do **not** rewrite those as raw
SQL — that would fork the traversal rules `CLAUDE.md` protects.

### Phase 4 — Global cache layer

Store: a file cache under the Omeka install's writable `files/` directory, or the
`Omeka\Settings` table. **Global, not per-session** — a session cache would make
every first-time visitor pay full price.

Invalidation: key on the collection's last-modified stamp (one cheap indexed
max-`modified` query over items) rather than a TTL. Data changes → key changes →
cache rebuilds; nothing changes → it never expires. That gives both correctness
and an effectively infinite expiry window.

Cache targets: `HomeGraph::typeCount()` results, the discover-tile selection, and
the three search facet lists.

Accepted trade-off: the first visitor after a data change pays the rebuild (a
stampede of one). Can be mitigated later with serve-stale-while-refresh or a
warming request.

## Explicitly out of scope

- Rewriting the derived-facet search branches as raw DQL/SQL. Forks the traversal
  rules, loses the adapter's site-visibility filter, high risk.
- The Photographs (20) folded-into-Documents gap — pre-existing, not a perf issue.
- Frontend asset work (Typekit, unpkg CDN scripts in `layout.phtml`) — real, but
  small next to a 6s TTFB.

## Verification

- Re-run `?hgdebug=1..4` and `?fsdebug=...`; compare bootstrap, per-stage and
  query-count numbers against the Phase 0 baseline table.
- Confirm 3.2 leaves result sets unchanged: for a sample of queries (bare
  `?types[]=17`, a title term, a term + decade, a term + domain), compare total
  count and the first page of ids before and after.
- Confirm discover tiles still always render an image after 2.1 — reload the home
  page ~20 times, no empty `.image-box`.
- MySQL slow query log empty at a 0.1s threshold for both pages.
- Lint changed PHP with the local PHP 8.3 binary (path in `CLAUDE.md`).

## Documentation policy

Per `CLAUDE.md`: Phase 4's cache mechanism and Phase 0's probe params are
architectural additions, so update `CLAUDE.md` in the same change. Phase 2.3
changes a traversal implementation, so record it in `Relationships.md`.
