# Optimization plan: cut home + search page load from 8–10s

## Status

| Phase | State |
|---|---|
| 0 — Measure | **Complete** (2026-08-25). Probe shipped, environment diagnosed, budget measured. |
| 1 — Environment | **Complete 2026-08-25.** OPcache then APCu installed by the Omeka team: bootstrap **1.77s → 0.063s**. |
| 2 — Home page | **Done 2026-08-25.** 2.1 / 2.2 / 2.4 landed; 2.3 implemented, measured as a regression, reverted. 2.5 tooling added. |
| 3 — Search page | **Closed 2026-08-25, not implemented.** The results page now runs end to end in 0.19s; there is nothing left to fix. |
| 4 — Global cache | **Closed 2026-08-25, not built.** Its three targets were host contention and a missing opcode cache. Design kept below in case it is ever needed. |

**Outcome:** home page ~6s → **1.5s** to first render and ~8–9s → **3s** fully populated;
search results ~4.4s → **0.19s**; framework bootstrap 1.77s → **0.063s**. Reached by the
Omeka team installing OPcache and APCu (Phase 1) and by removing three round-trip patterns
from the theme (Phase 2). Phases 3 and 4 were closed unbuilt: re-measurement showed the costs
that justified them were not real. **This plan is finished.**

> ⚠️ **Every measurement in this document from "Phase 0 results" up to the Phase 2 tables was
> taken on a host with no opcode cache.** OPcache was installed mid-session on 2026-08-25 and
> cut framework bootstrap from 1.77s to 0.079s. Separately, the earlier runs show DB round
> trips peaking at 8–12ms where they now peak at 0.15ms: the Omeka team have since confirmed
> that heavy queries were running on the box earlier that day. Both effects inflate every
> pre-OPcache number here, MySQL-side ones included. **Re-measure before acting on any of
> them** — that is what closed Phases 3 and 4, and it is the first thing to do if this plan is
> ever reopened.

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

## Root-cause hypothesis (to confirm in Phase 0) — RESOLVED

> Superseded by "Phase 0 results" below. Verdicts: **#1 dead** (MySQL is on localhost,
> 0.04ms round trip), **#2 confirmed**, **#3 dead** (Xdebug not installed), **#4 confirmed**
> (OPcache not installed at all). The "~3.5s unaccounted for" in the section above measured
> out at **1.77s** of framework bootstrap once timed request-relative rather than
> template-relative; the rest was network and TLS in the original browser measurement.

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

Home page budget from the probe (`?hgdebug=3`), request-relative:

| Stage | Wall | Queries | SQL time | Share |
|---|---|---|---|---|
| Framework bootstrap | 1.78s | — | — | 40% |
| `imagePool` window reads | 0.45s | ~10 | ~0.23s | 10% |
| `imagePool` thumbnail checks | **1.38s** | 60 | 0.55s | **31%** |
| Hero | 0.13s | 6 | 0.03s | 3% |
| Discover tiles | 0.66s | 80 | 0.55s | 15% |
| **Total to tiles** | **4.41s** | **156** | **1.36s** | |

Peak memory 40MB against a 128M limit. `SELECT 1` round trip: median **0.04ms**, so query
cost is real execution time, not connection overhead.

Notes from the query report:

- The N+1 is confirmed: **65 executions of the same media SELECT, 617ms**, i.e.
  `primaryMedia()` issuing a `findOneBy` per item. At ~9ms of SQL per item inside a stage
  costing ~23ms per item, roughly **half the thumbnail cost is PHP-side** (hydrating each
  media entity into a representation) — so Phase 1 and Phase 2.1 both bite on this stage.
- Overall, 1.36s of the 2.63s of theme work is SQL; the remaining ~1.27s is PHP execution.

**New findings, not in the original plan:**

- **`typeCount()` counts are expensive.** 8 executions totalling 287ms, 52–69ms each — two
  inside `imagePool`, four for the discover tiles. On a 4k-item table that is far slower
  than it should be; the subquery with its `resource`/`item` joins and visibility filter is
  the suspect. Already a Phase 4 cache target; now quantified at ~0.3s recoverable. Worth
  an `EXPLAIN` before assuming caching is the only answer.
- **`ComposeResourceTitle` re-resolves metadata per resource.** The discover-tiles stage
  fires 27 property lookups, 17 item hydrations and 12 custom-vocab queries to render
  **four tiles** (~0.2s). Memoizing the property/vocabulary lookups is a small, isolated
  win that belongs alongside Phase 2.
- The Comment module issues 3 queries on the home page although nothing there shows
  comments (~25ms). Low priority, but pure waste.

**Expected wins:**

| Change | Saving | Effort |
|---|---|---|
| Phase 1 — OPcache + APCu | 1.78s bootstrap, plus a share of the ~1.27s PHP-side theme time | one request, no code |
| Phase 2.1 — defer thumbnail checks to `discoverTile()` | ~1.3s | small |
| Phase 4 — cache `typeCount()` | ~0.3s | medium |
| New — memoize `ComposeResourceTitle` metadata | ~0.2s | small |

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

### Phase 1 — Environment — REQUESTED 2026-08-25, awaiting the Omeka team

Root-only, so it is a request rather than work we do. Sent:

- `dnf install php-opcache php-pecl-apcu`
- `/etc/php.d/10-opcache.ini`: `enable=1`, `enable_cli=0`, `memory_consumption=256`,
  `interned_strings_buffer=16`, `max_accelerated_files=32531`, `validate_timestamps=1`,
  `revalidate_freq=2`, and **`save_comments=1`** — this one must not be turned off, since
  Doctrine reads its entity mapping from annotations in docblocks and stripping comments
  would break Omeka outright.
- `/etc/php.d/40-apcu.ini`: `apc.enabled=1`, `apc.enable_cli=0`, `apc.shm_size=64M`
- `systemctl restart php-fpm`
- `chown apache:apache /var/www/html/logs/*.log` — logging is enabled in `local.config.php`
  but the files are `root:root`, so everything has been silently discarded since 2025-12-04

`validate_timestamps=1` with `revalidate_freq=2` is deliberate: deployment is `git pull` on
the host, so changes need to appear without an FPM restart.

**When it lands**, re-run the probe URLs in `CLAUDE.md` and compare against the Phase 0
budget table above. Both the bootstrap line and the PHP-side half of each theme stage should
drop. Not requested: raising `memory_limit` from 128M (peak measured at 40MB, so it is not a
constraint) and any FPM pool tuning (no evidence it is needed).

### Phase 1 result — complete 2026-08-25

Measured on an idle host (7 minutes without a request, so nothing is warm except OPcache
itself), `?hgdebug=1`:

| | Pre-OPcache | Post-OPcache |
|---|---|---|
| Framework bootstrap | 1.70–1.78s | **0.079s** |
| Peak memory (real) | 22MB | **6MB** |
| Total to end of pool stage | 2.18s | **0.130s** |
| The 10 pool queries, SQL time | 0.161s | 0.034s |
| DB round trip, median / max | 0.04ms / 8–12ms | 0.06ms / 0.15ms |

The memory drop is the same effect as the speed-up: compiled classes live in OPcache's shared
memory instead of being re-parsed into each request's heap. The SQL-side improvement is
**not** attributable to OPcache — nothing in an opcode cache touches MySQL execution time —
and the max round-trip figures suggest the earlier runs were contending with something else on
the host. Treat the pre-OPcache SQL timings as unreliable rather than as a baseline.

What the team applied, read out of the probe's environment block:

- `opcache.enable=1` with **`save_comments=1`** — essential, since Doctrine reads its entity
  mapping from docblock annotations — and `validate_timestamps=1` / `revalidate_freq=2` as
  requested, so `git pull` deployments still appear without an FPM restart.
- `memory_consumption=128` (30.3MB in use), `max_accelerated_files=10000` (1147 scripts
  cached), hit rate 96.4%, no OOM restarts. Both limits have headroom; the requested 32531
  file limit turned out to be unnecessary.
- `opcache.jit=tracing`, which we did not ask for. Leave it: JIT does little for I/O- and
  query-bound page rendering, and it is not doing harm.
- PHP moved 8.3.31 → 8.3.32 in the same package update.
- **APCu followed later the same day** (`apcu: enabled`), taking bootstrap from 0.079s to
  **0.063s** and the OPcache hit rate to 99.5% over 1504 scripts. A modest last 16ms, and for
  a structural reason worth knowing: `EntityManagerFactory` uses APCu for the **metadata**
  cache only, and then deliberately forces `setQueryCacheImpl(new ArrayCache())` as a
  workaround for SQL filters that vary by user and permission level. So entity annotations are
  now parsed once into shared memory and reused by every FPM worker instead of once per
  request, but DQL → SQL compilation stays per-request by design and no ini setting changes
  that. Do not expect more from APCu here.
- The probe reports the Doctrine cache drivers only by their wrapper class
  (`Psr6\CacheAdapter`), so it cannot show which pool is behind them; the APCu switch is
  inferred from `extension_loaded('apcu')` in `EntityManagerFactory`, which is the actual
  condition core tests.
- The `chown apache:apache logs/*.log` request was carried out too. `files/` still reports
  `writable=NO`, which is expected — only the log files were requested, and the `Settings`
  table remains the right store for anything cached (Phase 4, closed).

### Phase 2 — Home page round-trip reduction

**2.1** Stop eagerly checking 60 thumbnails in `HomeGraph::imagePool()`. The pool
is already filtered by `has_media=1`, and only ~4 items ever become tiles. Move
the thumbnail check into `HomeGraph::discoverTile()` so it validates the
candidate it picks and skips to the next if there is no derivative. Same
guarantee ("a tile always has an image"), ~60 lookups down to ~4–10. Expected
saving ~1.3s, no behaviour change.

> **Done.** `imagePool()` now shuffles and returns the two windows untouched;
> `discoverTile()` walks to the type first and only then resolves the large
> derivative, skipping the candidate if there is none. Type-walk-first is
> deliberate: the walk rejects far more candidates than a missing derivative
> does, so thumbnail checks land at ~1 per tile. The URL found by the check is
> returned in the tile, so the view does not repeat the lookup.

**2.2** Same treatment in `HomeGraph::hydrateInOrder()` for the async fragment.

> **Done.** Hydration is now lazy per `HYDRATE_CHUNK`: the next chunk is read
> only if the previous ones have not yet produced `$limit` items, so the usual
> case is one chunk of 40 rather than all 120 candidate ids. Chunks are consumed
> in `$ids` order, so the output order is unchanged. `hydrateInOrder()` and
> `orgDescendantPool()` now return `['item' => rep, 'imageUrl' => string]`
> entries: `thumbnailDisplayUrl()` is **not** memoized on the representation
> (`primaryMedia()` re-runs its `findOneBy` on every call), so the fragment was
> paying a second query per item to render the `<img>` it had already validated.

**2.3** Reduce the id-query count in `orgDescendantPool()` — currently up to ~50
single-target reverse lookups. Batch the per-event Document/Photograph lookups
into one OR'd query per org instead of two per event.

> **Implemented, measured, reverted (2026-08-25).** `subjectIdsForAny()` batched
> each Organization's sampled Events into one OR'd `res` query across both child
> templates. The query count fell as intended — the whole fragment came to 47
> queries — but **each batched lookup cost ~615ms**: 11 executions of that shape
> totalled 3.13s, and the fragment's SQL rose to 3.47s of 4.43s template time,
> roughly doubling the wait it was supposed to shorten. So MySQL will not drive
> the values join from an OR of `valueResource` equalities the way it does from a
> single equality, and 50 cheap indexed lookups beat 10 expensive ones. Reverted
> to the per-event form, and `HomeGraph`'s docblock now warns against retrying it
> blind.
>
> Two things changed at once in the batched query — the OR'd property rows and
> widening to `resource_template_id IN (15, 20)` — so which one MySQL choked on
> is not yet established. `?selected_items=1&hgdebug=4&probeexplain=2` on the
> fragment URL answers that. If it turns out to be the OR alone, merging just the
> two templates into one query per Event is still worth ~14 queries per request,
> but it does not ship without a fragment measurement.

**2.4** *(new, from the Phase 0 probe)* Memoize the property and vocabulary lookups in
`helper/ComposeResourceTitle.php`. Rendering four discover tiles currently costs 27 property
lookups, 17 item hydrations and 12 custom-vocab queries (~0.2s) because the same metadata is
re-resolved per resource. A per-request static cache keyed by property term / vocab id is
enough; nothing about the composed output should change.

> **Done**, though the mechanism is one level up from where the plan put it. The
> helper never resolves a property or a vocabulary itself: those queries come out
> of core's `values()`, which builds a `ValueRepresentation` per value (each one
> resolving its data type — hence the custom-vocab queries) and calls
> `property()` and `isHidden()` on it. `values()` is memoized *per representation
> object*, so the cost is paid again whenever the same entity arrives as a new
> object — which is exactly what happens to an Event reached through several
> Documents. So the memo is keyed by `<resourceName>:<id>`: `parts()`,
> `templateId()`, `literal()` and `resourceValues()` each cache per resource
> (plus term), and the Document → Event branch now recurses through `parts()`
> rather than `eventParts()` so the Event lands in the cache. Theme view helpers
> are shared for the whole request (`MvcListeners` registers them as factories on
> the shared `ViewHelperManager`), so instance properties are enough; no statics.
> The four Discover tiles are distinct resources and gain little — the win is in
> the 32-item async fragment, where Documents share Events.

**2.5** *(new, from the Phase 0 probe)* `EXPLAIN` the `typeCount()` `COUNT(*)`. It runs
52–69ms against a 4k-item table on a database with 0.04ms round-trip latency, which is far
slower than it should be. Do this **before** implementing the Phase 4 cache for it — if the
cause is a missing index, caching would paper over a problem that also slows the search
page, where the same counts drive pagination.

> **Tooling added, plan pending.** `?probeexplain=N` on either probe URL prints
> the N slowest logged `SELECT`s in full, with their bound parameters and MySQL's
> plan. Reconstructing the statement by hand was not worth it: what the
> paginator's count query compiles to depends on which Doctrine count walker the
> `GROUP BY` selects. Documented in `CLAUDE.md`. Run it with `hgdebug=1`, where
> the two `typeCount()` counts are the dominant statements now that the 60
> thumbnail lookups are gone.

### Phase 2 results (measured 2026-08-25)

Loopback, on the host. **The two tables below are pre-OPcache**, so they are a valid
before/after against Phase 0 but not a description of the site as it now runs. OPcache landed
between these runs and the fragment runs further down; each block below says which side of
that line it falls on.

Home page total: **3.57–4.22s** across 5 runs, against Phase 0's 4.37–5.20s.

To the discover tiles (`?hgdebug=3`), request-relative:

| Stage | Phase 0 | After 2.1 / 2.4 |
|---|---|---|
| Framework bootstrap | 1.78s | 1.70s |
| `imagePool` | 1.83s (0.45 windows + 1.38 thumbnails) | **0.51s** |
| Hero | 0.13s | **0.78s** |
| Discover tiles | 0.66s | 0.29s |
| **Total to tiles** | **4.41s** | **3.58s** |
| Queries / SQL time | 156 / 1.36s | **90 / 0.71s** |

The 65-execution media SELECT that cost 617ms is gone from the query report entirely, so
2.1 did what it was for. But **~0.7s of that 1.38s only moved**: the hero went from 0.13s
to 0.78s on 6 queries and 0.039s of SQL — ~0.74s of pure PHP — and peak memory between the
stage-1 and stage-2 dumps rises from 22MB to 38MB. The first `thumbnailDisplayUrl()` of a
request loads the thumbnail stack and the S3FileStore module's AWS SDK, ~16MB of classes
re-parsed from disk on every request with no opcode cache. `imagePool` used to pay that
cold start; the hero pays it now. **So 2.1 is worth ~0.83s, not ~1.3s**, and the rest is
Phase 1's to recover — it was never a query cost.

Async fragment (`?selected_items=1&hgdebug=4`), pre-OPcache: 2.2 confirmed — 32 media SELECTs
instead of ~64, one hydrate chunk instead of three (40 candidates interleaved, not 120). 2.3
regressed and was reverted (above). 2.4 cannot be isolated from these dumps; it is a small win
by construction and no longer a suspected hot spot.

Same fragment after the revert, post-OPcache: **0.556s total**, 90 queries in 0.087s, the 54
single-target reverse lookups costing 48.4ms *between them* (~0.9ms each, against the 615ms
per query the batched form cost). Not comparable to the Phase 0 fragment baseline, but it does
settle the shape question: many small indexed lookups are the right call here.

**Verification, per the section below:**

- `?hgdebug=1..3` re-run and compared: the table above.
- 2.1's guarantee: 20 consecutive home page loads, every one with four `.image-box` divs and
  four `<img>` inside them. No empty box. The stage-3 dump also shows each of the four tiles
  matching on its *first* thumbnail check, which is the behaviour 2.1 was aiming for.
- 2.5's `EXPLAIN` ran after the revert had deployed, so the batched statement no longer
  existed to explain and it profiled the property-term query instead. The OR-versus-`IN
  (15, 20)` question is therefore unresolved and deliberately left that way: re-instrumenting
  a reverted regression to recover ~14 queries that now cost 0.9ms each is not worth it.
- No `?fsdebug` run: nothing in Phase 2 touches the search path.
- MySQL slow query log unchecked — the log path and threshold from Phase 0 were never
  recorded here.

**Still open, in the order they now matter:**

- **Everything below needs re-measuring post-OPcache before it is worth doing.** On a page
  that renders in 1.5s, a `Settings`-table cache with stamp invalidation may cost more in
  complexity than it returns.
- `discoverTile()`'s graph walk fired 27 property lookups, 15 custom-vocab and 9 value queries
  for four tiles (pre-OPcache). The Phase 0 note attributed these to `ComposeResourceTitle`;
  they are actually `walkToType()` hydrating each candidate's `dcterms:relation` targets, and
  the memo added in 2.4 does not touch them. This is the largest remaining theme-side item on
  the home page.
- `typeCount()` was 8 executions / 288ms pre-OPcache. Since the whole 10-query pool stage now
  runs in 34ms, the "52–69ms per count" finding was probably contention, not a missing index.
  Re-measure before building the Phase 4 cache for it.
- The property-term query (`SELECT CONCAT(vocabulary.prefix, ':', property.local_name) …`) is
  now the slowest single statement at 23.5ms, doing `Using temporary; Using filesort` over 8
  vocabularies × ~37 properties. Once per request, so it is small, but it is a clean cache
  target if a cache is built anyway.
- The ~0.7s first-thumbnail cold start that 2.1 exposed (the thumbnail stack plus the
  S3FileStore module's AWS SDK) was class loading, so OPcache should have absorbed most of it.
  Worth confirming in the re-baseline rather than assuming.

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

> **Closed 2026-08-25 without implementing either item.** Post-OPcache, the whole results
> page for `?types[]=17` (1,171 matching items) measures **0.193s** request-relative, 12
> queries, 0.081s of SQL:
>
> | stage | cumulative | queries | SQL |
> |---|---|---|---|
> | bootstrap | 0.088s | — | — |
> | `computeResultIds` (1171 ids) | 0.099s | 1 | 0.008s |
> | `pageResults` (24) | 0.145s | 5 | 0.049s |
> | `decadeFacets` (8) | 0.173s | 8 | 0.064s |
> | `domainFacets` (5) | 0.184s | 10 | 0.074s |
> | `eventTypeFacets` (6) | 0.193s | 12 | 0.081s |
>
> The three Reference aggregations that 3.1 would have cached cost 15ms, 10ms and 7ms. And
> `computeResultIds` resolves all 1,171 ids in a single 8ms query, so the resolve-then-union
> that 3.2 would have bypassed is not a cost either — nor would it be at the 2× growth
> ceiling. Both items remain written up above in case the collection or the query shapes
> change; neither is worth implementing now.

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

> **Closed 2026-08-25, not built.** All three cache targets evaporated on
> re-measurement: `typeCount()`'s 288ms was host contention (the whole 10-query pool stage
> now runs in 34ms), the facet aggregations cost 7–15ms each (Phase 3 above), and the
> discover-tile selection is no longer expensive now that it checks ~1 thumbnail per tile.
> What remained would be a global cache with stamp invalidation — permanent complexity and a
> stale-data surface — bought for well under 0.1s on pages that render in 0.2–1.5s.
>
> Kept as a design rather than a plan. If a future data set or a genuinely expensive new
> aggregation justifies a cache, both decisions above still hold: store it in the `Settings`
> table (because `files/` is not writable by `apache`) and key it on a last-modified stamp
> rather than a TTL.

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
