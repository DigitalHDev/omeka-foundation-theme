# Data Model & Relationships

Single reference for the Event-centric graph this theme traverses. Read this before
writing or changing any code that walks between item types (helpers, page templates,
search). Traversal rules are governed by the **SecondDegreeResources usage policy** in
`CLAUDE.md`: reuse the rules below, do not fork them, and discuss before changing them.

---

## 1. Resource templates

| Type          | Template ID | Resource class         | Notes |
|---------------|-------------|------------------------|-------|
| Documents     | 15          | `ceramic:Document`     | Carries media (images); may be a video (see §5) |
| Events        | 16          | `ceramic:Event`        | The hub of the graph; has **no media of its own** |
| People        | 17          | `ceramic:Person`       | |
| Organizations | 18          | `ceramic:Organization` | The curated set for the home page is item set **4211** |
| Photographs   | 20          | *(photograph template)*| Behaves like a Document in every traversal |

Documents (15) and Photographs (20) are always traversed as a pair. In the faceted
search UI they are also *displayed* as one "מסמכים" checkbox.

## 2. Properties used as edges

| Property | ID | Used for |
|---|---|---|
| `dcterms:relation` | 13 | Doc/Photo → Event, Doc/Photo → Org, Event → Org (affiliation/host) |
| `ceramic:creator` | 501 | Event → Agent (the primary creator role) |
| sibling creator roles | 502, 512, 518, 511, 514, 503, 500, 506, 504 | Event → Agent under other roles (curator, photographer, supporter, …) |
| `dcterms:title` | 1 | Search (`in` = contains) |
| `dcterms:date` | — | Sorting and decade facets |
| `dcterms:type` | — | Event type facet; also marks a Document as video (`וידאו`) |
| `cidoc:P19i_was_made_for` | — | Event *domain* facet (custom vocab #9); only on Events whose `dcterms:type` is `אירוע` |

The **ten** creator-role property ids are declared as `ROLE_PROPS` in
[helper/ItemRelations.php](helper/ItemRelations.php) — **the source of truth for the role
set** — and mirrored in [helper/FacetedSearch.php](helper/FacetedSearch.php). The array's
order is the Event template's own declaration order and drives group order on every page
that renders roles. Their human labels are not hardcoded — `ItemRelations::eventsByRole()`
and `creatorGroups()` read each property's Event-template alternate label at runtime, so it
appears in the site language.

**A role property's value is an Agent, not necessarily a Person.** All ten have data type
custom vocab **#1 "Agents"** = item set **868**, which holds both People (1168) and
Organizations (164), so an Organization is a legitimate value of any of them. Live counts of
org values: 518 supporter → 42, 511 partner → 31, 501 creator → 23, 506 entrepreneur → 1;
518 and 511 are org-only (no person values at all).

`dcterms:relation` (13) on an Event also points at an Organization, but it means something
different — affiliation/host, not a credit. The two edges coexist and are rendered
differently (see §4).

## 3. Edge directions (which item holds the value)

Direction matters: every edge below is stored on the item at the **tail** of the arrow,
so walking the other way is a *subject* (reverse) lookup — an API query of the form
`property[0] = {property: <id>, type: 'res', text: <targetId>}`.

```mermaid
graph TD
    D["Document (15)"] -->|dcterms:relation 13| E["Event (16)"]
    P["Photograph (20)"] -->|dcterms:relation 13| E
    E -->|dcterms:relation 13 - affiliation| O["Organization (18)"]
    E -->|role props - credit| O
    E -->|ceramic:creator 501 + sibling roles| PE["Person (17)"]
    D -.->|dcterms:relation 13 - direct, secondary| O
```

- **Document/Photograph → Event** — the Doc/Photo holds the value.
- **Event → Organization** — the *Event* holds the value, under **either** of two edges that
  mean different things: `dcterms:relation` (13) is affiliation/host, the creator-role
  properties are credits. An Org page therefore finds its Events by reverse lookup on both
  (`ItemRelations::events()`), and `eventsByRole()` keeps them apart — role groups labeled,
  affiliation unlabeled, no Event in two groups.
- **Event → Person** — the *Event* holds the value, under one of the creator-role
  properties. A Person page finds their Events by reverse lookup.
- **Event → Agent** — Person and Organization are interchangeable as the value of any role
  property (§2); code that walks a role edge must not assume template 17.
- **Document → Organization (direct, dashed)** — exists in the data, but is **not** part
  of the canonical Organization second-degree config and is deliberately not used for the
  home page's "פריטים נבחרים" section.

People and Documents/Photographs are **siblings** under an Event. A Person is never an
intermediate node between an Organization and a Document.

## 4. Standard traversals

| From | To | Path | Implemented in |
|---|---|---|---|
| Person | Events | reverse via role props | `ItemRelations::events()` / `eventsByRole()` |
| Org | Events | reverse via 13 **and** role props | `ItemRelations::events()` / `eventsByRole()` |
| Person/Org | Docs + Photos | Events, then reverse via 13 | `ItemRelations::relatedByTemplate()`, `galleryTiles()` |
| Org | People | Events, then forward via role props | `linked-resources.phtml` config 18 → "People in Events" |
| Doc/Photo | Event / Org / Person | forward via 13, then Event's values | `HomeGraph::walkToType()` |
| Org item set | Docs + Photos (grandchildren) | Orgs → reverse 13 → Events → reverse 13 → Docs/Photos | `HomeGraph::orgDescendantPool()` |
| Doc | Event's domain / type | forward via 13, then Event's `cidoc:P19i_was_made_for` / `dcterms:type` | `FacetedSearch` |
| Doc/Photo | parent Event | forward via 13, first value with template 16 | `ItemRelations::parentResource()` |
| Event | parent Org | forward via 13, first value with template 18 | `ItemRelations::parentResource()` |
| Event | its People, by role | forward via role props | `ItemRelations::creatorGroups()` |
| Event | its Orgs, by role | forward via role props | `ItemRelations::creatorGroups()` |
| Doc/Photo | its People, by role | parent Event, then forward via role props | `ItemRelations::creatorGroups()` |
| Event | Docs + Photos | reverse via 13, both templates merged | `ItemRelations::relatedDocsAndPhotos()` |

The last five rows feed the item-child item pages (`view/common/item-document.phtml`,
`view/common/item-event.phtml`) — see **Item show pages** in `CLAUDE.md`. They introduce no
new edges: `parentResource()` walks the existing Doc/Photo → Event and Event → Org edges
forwards, and `creatorGroups()` walks the existing Event → Person role edges forwards.
`parentResource()` falls back to a directly-referenced Org or Person for a Document that has
no Event (the dashed edge in §3), since otherwise such a Document would show no parent bar.

Both hops in `HomeGraph::orgDescendantPool()` are **single-target** reverse lookups, one
query per Event per child template. Batching them into one OR'd `res` query per
Organization was implemented and reverted: the OR'd form measured ~615ms per query against
~40ms for the single-target form (optimization.md 2.3). The edge, its direction and the
templates are not what is at issue — this is purely how the query is shaped — but the
per-target form is the one to keep.

The `$secondDegreeConfigs` array in
[view/common/resource-page-block-layout/linked-resources.phtml](view/common/resource-page-block-layout/linked-resources.phtml),
consumed by [helper/SecondDegreeResources.php](helper/SecondDegreeResources.php), remains
the production-tested configuration for the block-based path — but it is **no longer the
source of truth for the role set**: that designation now belongs to
`ItemRelations::ROLE_PROPS` (§2). Templates 15/16/17/18/20 all route to bespoke partials in
`view/omeka/site/item/show.phtml`, so those configs are unreachable for them, and they have
been left un-updated deliberately (see §6.3). Changing them still requires discussion first,
per the SecondDegreeResources usage policy.

## 5. Node quirks

- **Events have no media.** Anything that needs a picture for an Event borrows one from a
  connected Document (`ItemRelations::eventThumbnail()`, and the home page Discover tiles,
  which start from an image-bearing Doc/Photo and walk *up*).
- **Video Documents.** A Document/Photograph whose `dcterms:type` is `וידאו` has no image
  media; its tile is a YouTube embed built from `bibo:uri`.
- **Site scoping.** `ItemRelations`, `HomeGraph` and `FacetedSearch` always add `site_id`
  to their queries. `SecondDegreeResources` only does so when the
  `exclude_resources_not_in_site` site setting is on.

## 6. Known inconsistencies (open)

1. **`HomeGraph` narrows the Event → Person edge to `ceramic:creator` (501) only.**
   [`HomeGraph::walkToType()`](helper/HomeGraph.php) uses `firstResourceValue($event,
   'ceramic:creator', TPL_PERSON)`, whereas `ItemRelations::ROLE_PROPS` uses all ten role
   properties (and the Organization config in `linked-resources.phtml` nine). A Person
   linked to an Event only under another role (e.g. curator, 502) can never surface in the
   home page's "אנשים" Discover tile. Left as-is deliberately; revisit if the tile looks
   too narrow.
2. **The canonical config itself disagrees on the Person → Event edge.** The Person block
   (template 17) in `linked-resources.phtml` uses `firstDegreePropertyIds: [501]`, while
   `ItemRelations::events()` uses all ten `ROLE_PROPS` for the same hop. Pre-existing;
   the two code paths can therefore return different Event sets for the same Person.
3. **`linked-resources.phtml` now lags `ROLE_PROPS` on the role set.** Its Organization
   config still lists the nine pre-2026 role ids and its Person config still lists only
   `[501]`, so neither knows about `512 ceramic:photographer`. Left as-is deliberately: the
   file is unreachable for templates 15/16/17/18/20 (all route to bespoke partials), and
   editing it would change `SecondDegreeResources` behaviour, which CLAUDE.md requires be
   discussed first.
4. **`FacetedSearch::resourcesConnectedToEvents()`'s Organization branch is still
   `dcterms:relation`-only.** It does not follow role edges, so an Organization credited on
   an Event but not affiliated with it is not matched by that branch, even though its item
   page now lists that Event. Deliberately out of scope of the 2026-08 change.

Other open items that touch the graph are listed under **Faceted search / results page →
Open items** in `CLAUDE.md`.

## 7. Resolved

- **`ceramic:photographer` (512) was missing from `ROLE_PROPS`** (fixed 2026-08). The Event
  template declares it between 502 and 518; 84 Events carry it, crediting 29 distinct People,
  all of them People (no orgs). While it was absent, no Event page rendered a צלם/ת credit
  and 27 of those 29 People — who hold no other role — had an empty related-items section.
- **Organizations were dropped from the Event → Agent edge** (fixed 2026-08).
  `creatorGroups()` filtered value-resources to template 17, silently discarding 97 org
  credits, and `events()` found an Org's Events through `dcterms:relation` only, leaving 53
  of the 58 role-linked Organizations with an empty item page.
