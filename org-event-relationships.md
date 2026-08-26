# Organizations as role-bearing agents on Events

Working document for the change. The kickoff prompt for implementing it in a fresh session is
in [org-event-relationships-prompt.md](org-event-relationships-prompt.md).

Status: **planned, not implemented.**

## Context

Events credit People under nine creator-role properties (`ceramic:creator`, `ceramic:curator`, …).
The Event page renders those credits as a role-grouped link cloud, and each Person page lists
the Events they took part in, grouped by the same roles.

Organizations participate in Events under **exactly the same properties** — the theme just
never looks for them. Confirmed against the live data (all 342 Events scanned via the API):
the role properties' data type is custom vocab **#1 "Agents"**, an item set (868) holding **1168
People and 164 Organizations**, so an org is a legitimate value of any role property.

| prop | role (Event-template alt label) | org values | person values |
|---|---|---|---|
| 518 `ceramic:supporter` | תומכ/ת | 42 (11 events) | 0 |
| 511 `ceramic:partner` | שותף/ה | 31 (21 events) | 0 |
| 501 `ceramic:creator` | יוצר/ת | 23 (16 events) | 1852 |
| 506 `ceramic:entrepreneur` | יזמ/ת | 1 (1 event) | 0 |
| 13 `dcterms:relation` | שיוך (affiliation, not a role) | 336 (334 events) | — |

Two consequences today:

1. `ItemRelations::creatorGroups()` filters value-resources to `TPL_PERSON`, so **97 org
   credits are silently dropped** from Event pages, and the two roles that are org-only
   (518 supporter, 511 partner) never render a group at all.
2. `ItemRelations::events()` finds an Org's Events through `dcterms:relation` (13) only.
   **53 of the 58 role-linked Organizations have no property-13 Event**, so their pages show
   no related-items section whatsoever. Verified live: org **1745** (ראטב אלפאח'ורי, חברון,
   creator on one Event) renders zero `archive-item` cards; org **1815** (בית אהרון כהנא,
   63 property-13 Events) renders one unlabeled group of 127 cards.

The outcome: Organizations appear in the Event page's role cloud alongside People, and
Organization pages list their Events grouped by role, the way Person pages already do.

### Also in scope: `ceramic:photographer` (512)

`ROLE_PROPS` lists nine property ids. The Event resource template (`/api/resource_templates/16`)
declares **ten** role properties — `512 ceramic:photographer`, alt label **צלם/ת**, sits between
`502 ceramic:curator` and `518 ceramic:supporter` in the template and is absent from the
constant. It is not a rare property: **84 Events** carry it, crediting **29 distinct People**
(top photographer: person 1645 on 31 Events; then 1373 on 13, 1130 on 8). All 84 values are
People — no orgs use it.

Because `ROLE_PROPS` is the whole role vocabulary for both directions of traversal, the omission
costs twice:

- No Event page anywhere shows its photographer credit — `creatorGroups()` skips property 512,
  and it is not in `item-event.phtml`'s details-drawer list either, so the value is invisible on
  the site.
- Photographers' Person pages find no Events through it. **27 of the 29** appear under *no other*
  role property, so their Person pages currently show an empty related-items section — the same
  failure mode as the 53 role-only Organizations, from the same root cause.

Adding 512 is a one-line fix in the same constant this change is already editing, and it is the
change with the largest visible effect per line. It is a behaviour change beyond organizations:
84 Event pages gain a צלם/ת group and 29 Person pages gain events.

## Decisions and assumptions

- **Event page rendering:** one group per role in the existing creators cloud. People first, then
  Organizations, each in the order the values appear on the Event. No visual distinction between
  the two, so **no CSS and no JS changes**.
- **The `dcterms:relation` (13) edge is not a role.** On an Org page its Events stay an
  *unlabeled* group (preserving what org 1815 renders today), with the labeled role groups
  following. Role groups take priority: an Event reachable both ways is listed under its role,
  not twice.
- `view/common/resource-page-block-layout/linked-resources.phtml` is **not** touched. Templates
  15/16/17/18/20 all route to bespoke partials in `view/omeka/site/item/show.phtml`, so its
  `$secondDegreeConfigs` are unreachable for them; editing it would change
  `SecondDegreeResources` behaviour, which CLAUDE.md requires be discussed first.
  `Relationships.md` currently designates that file the source of truth for the role set — that
  designation moves to `ItemRelations::ROLE_PROPS`, recorded as part of this change.
- `FacetedSearch::resourcesConnectedToEvents()`'s **Organization** branch stays on
  `dcterms:relation` only (deliberately out of scope). Its `ROLE_PROPS` mirror still gains 512,
  so the two constants do not drift.

## Changes

### 1. `helper/ItemRelations.php` — the substance of the change

**a. `ROLE_PROPS` (line 45)** — insert `512` after `502`, matching the Event template's own
declaration order (that order drives group order everywhere):

```php
const ROLE_PROPS = [501, 502, 512, 518, 511, 514, 503, 500, 506, 504];
```

Update the docblock: these connect an Event to an **Agent** — Person *or* Organization — since
the properties' data type is custom vocab #1 (item set 868, "Agents"). Same wording in the class
docblock's traversal list (lines 17–23), where `Person -> Event` and `Org -> Event` are described
as separate rules.

**b. `creatorGroups()` (lines 229–263)** — stop filtering to `TPL_PERSON`; accept
`TPL_PERSON` and `TPL_ORGANIZATION`, bucketing each group's members into two lists so people can
be emitted before orgs:

```php
foreach ($info['values'] as $value) {
    $linked = $value->valueResource();
    $tpl = $linked ? $this->templateId($linked) : null;
    if ($tpl === self::TPL_PERSON) {
        $byProperty[$propertyId]['people'][$linked->id()] = $linked;
    } elseif ($tpl === self::TPL_ORGANIZATION) {
        $byProperty[$propertyId]['orgs'][$linked->id()] = $linked;
    }
}
```

Emit each group as `['label' => …, 'agents' => [...people, ...orgs]]`. **Rename the key
`people` → `agents`** — a group can now hold either — and update the two call sites
(§2 below). Dedupe stays per-property, by resource id, as now.

**c. `events()` (lines 73–88)** — an Organization's Events come from the affiliation edge
*and* the role edges:

```php
$templateId = $this->templateId($item);
$props = $templateId === self::TPL_ORGANIZATION
    ? array_merge([self::PROP_RELATION], self::ROLE_PROPS)
    : ($templateId === self::TPL_PERSON ? self::ROLE_PROPS : [self::PROP_RELATION]);
```

This also widens the Org page's second-degree sections — `relatedByTemplate()` and
`galleryTiles()` both consume `events()` — which is the intended effect.

**d. `eventsByRole()` (lines 123–143)** — drop the Person-only short-circuit and generalise to
both templates:

- Build the labeled role groups first, one per `ROLE_PROPS` entry that yields Events (unchanged
  logic, label from `roleHeading($events[0], $pid)`), collecting the ids used.
- For an Organization, prepend one **unlabeled** group (`'label' => ''`) holding the
  `PROP_RELATION` Events *minus* the ids already claimed by a role group.
- Any other template keeps today's fallback: one unlabeled group from `events()`.

Update the docblock, which currently states Organizations "relate to Events through a single edge
(dcterms:relation), so they return one unlabeled group."

No change to `roleHeading()`, `parentResource()`, `subjects()`, `cardData()` or `creatorNames()`.
`creatorNames()` already reads `ceramic:creator` value-resources without a template filter, so
the 23 org creators already surface in cards' artist column.

**Query cost.** An Org page goes from 1 subject query to 11 for its Events. `subjects()` is
memoized on `"$targetId-$templateId-$propertyId"` and `eventsByRole()` + `events()` hit identical
keys, so the two calls in `item-person-org.phtml` still cost 11 queries total — the same shape as
a Person page today (9, becoming 10 with 512).

### 2. `view/common/item-event.phtml` and `view/common/item-document.phtml`

One-line change in each — `$group['people']` → `$group['agents']` at
[item-event.phtml:119](view/common/item-event.phtml:119) and
[item-document.phtml:127](view/common/item-document.phtml:127). The surrounding
`.creators-sidebar-wrap` / `.creators-mini-header` / `.creators-link-cloud` markup is unchanged,
and org links resolve through `$agent->url()` exactly like people.

### 3. `view/common/item-person-org.phtml`

**No code change required.** `$eventGroups = $relations->eventsByRole($item)` now returns role
groups for Organizations, and the existing loop at lines 138–147 renders
`.result-subgroup-header` for every group with a non-empty label. The hero anchor chips
(lines 45–50, 88–95) are built from those labels and will start appearing on Org pages
automatically. Re-read the file when implementing to confirm nothing else assumes Orgs are
unlabeled.

### 4. `helper/FacetedSearch.php`

Add `512` to `ROLE_PROPS` (line 44) in the same position, keeping it a true mirror of
`ItemRelations::ROLE_PROPS` as its comment claims. Effect: the 27 photographer-only People become
reachable through the decade/domain facets. `resourcesConnectedToEvents()` itself is unchanged.

### 5. Documentation (required — this is a data-model change)

**`Relationships.md`:**
- §2 properties table: add `ceramic:photographer` 512 to the sibling-roles row (now ten ids);
  state that role properties take **Agents** (custom vocab #1 / item set 868 = People +
  Organizations), so an org is a valid value of any of them.
- §3 mermaid + prose: add `E -->|role props| O["Organization (18)"]` next to the existing
  `E -->|dcterms:relation 13| O`, and note the two edges mean different things — 13 is
  affiliation/host, the role props are credits.
- §4 traversals table: `Org → Events` becomes "reverse via 13 **and** role props"; add
  `Event → its Orgs, by role — forward via role props — ItemRelations::creatorGroups()`.
- §6 inconsistencies: record that 512 was missing from `ROLE_PROPS` and is now included; note
  `linked-resources.phtml`'s Person config still uses `[501]` only and the Org config still uses
  the nine-prop list, so the legacy path (unreachable for these templates) now differs from
  `ItemRelations` — and move the "source of truth" designation to `ItemRelations::ROLE_PROPS`.

**`CLAUDE.md`:** in the *Item show pages* section, note that the creators cloud now carries
Organizations as well as People, and that `creatorGroups()` returns `agents` rather than `people`.

## Verification

No local Omeka instance exists, so verification is `php -l` locally plus targeted page checks
after deploying to the host (`git pull` on `/var/www/html`).

**Lint (local):**

```bash
"C:/Users/gilsh/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe" -l helper/ItemRelations.php
```

…and the same for `helper/FacetedSearch.php`, `view/common/item-event.phtml`,
`view/common/item-document.phtml`.

**Live checks — concrete targets found in the data:**

| URL | Expect |
|---|---|
| `/s/CCC-1/item/1856` (הקדרות הפלסטינית המסורתית בחברון) | Best single target: carries orgs under **creator, supporter and partner** plus a person photographer. Cloud should show יוצר/ת (people + orgs), צלם/ת, תומכ/ת, שותף/ה |
| `/s/CCC-1/item/1842` (אגרטל עכשיו) | שותף/ה group with an org, where none renders today |
| `/s/CCC-1/item/1847` (סטלה להב) | צלם/ת group — a credit invisible on the site today |
| `/s/CCC-1/item/1745` (ראטב אלפאח'ורי, חברון) | Org page, currently **empty**: should gain a יוצר/ת group with 1 Event, plus hero anchor chip |
| `/s/CCC-1/item/1815` (בית אהרון כהנא) | Org page with 63 property-13 Events: unlabeled group must still render, with any role groups added and no Event duplicated across groups |
| `/s/CCC-1/item/1902` (חומר צעיר) | יזמ/ת group with an org |

A page-level regression check without opening a browser (counts only, so the page never has to be
read in full):

```bash
curl -sk "https://benyaminiceramics.omeka.net/s/CCC-1/item/1815" -o /tmp/o.html && for c in result-group-header result-subgroup-header archive-item hero-anchors; do echo "$c: $(grep -o "$c" /tmp/o.html | wc -l)"; done
```

Baselines measured before the change:

| item | result-group-header | result-subgroup-header | archive-item | hero-anchors |
|---|---|---|---|---|
| 1815 | 3 | 0 | 127 | 0 |
| 1745 | 0 | 0 | 0 | 0 |

After the change, 1815 should show `result-subgroup-header ≥ 1` and `hero-anchors 1`; 1745 should
go from all-zero to a rendered related-items section.

**Regression targets that must not change:** a Person page (role grouping untouched except for
the new צלם/ת group), a Document page (`item-document.phtml` borrows the parent Event's groups),
and the search results page (`?types[]=17` etc.) — the `ROLE_PROPS` edit widens People results
by the 27 photographers but must not alter Organization or Event results.

**Performance:** run `PerfProbe` on an Org page per the *Performance probes* section of
`CLAUDE.md` before/after, from the host over loopback, to confirm the 1→11 query change on
`events()` is absorbed by the `subjects()` memo and does not regress the page.

## Appendix: how the data was established

All figures above come from a one-off scan, not from guesses. Method, in case it needs repeating:

1. `curl` `/api/resource_templates/16` (and 17, 18) to list each template's properties with their
   ids, alternate labels and data types. The role properties' data type is `customvocab:1`.
2. `curl` `/api/custom_vocabs/1` → item set **868**, labelled "Agents".
   `/api/items?item_set_id=868&resource_template_id=17|18&per_page=1` and read the
   `Omeka-S-Total-Results` header: 1168 People, 164 Organizations.
3. Download all Events (`resource_template_id=16`, 4 pages of 100) and all Organizations
   (template 18, 2 pages) to local JSON, then intersect in a short PHP script: for each role
   property, count value-resource ids that are in the org set versus not.

Header-only counts of how many Events use each property at all
(`property[0][property]=<id>&property[0][type]=ex&per_page=1`):

| prop | events | prop | events |
|---|---|---|---|
| 501 creator | 324 | 503 designer | 5 |
| 13 relation | 335 | 500 consultant | 3 |
| 502 curator | 202 | 506 entrepreneur | 1 |
| 512 photographer | 84 | 504 donor | 1 |
| 511 partner | 21 | 522 mentionedOrganization | 7 |
| 518 supporter | 11 | 523 mentionedPerson | 8 |
| 514 producer | 6 | | |

`522 ceramic:mentionedOrganization` and `523 ceramic:mentionedPerson` are mentions rather than
credits and already render in the Event page's details drawer
([item-event.phtml:41-51](view/common/item-event.phtml:41)); they are deliberately **not**
promoted to role groups.
