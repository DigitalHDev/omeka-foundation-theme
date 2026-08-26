# Kickoff prompt — implement org-event-relationships.md

Paste everything below the line into a fresh session started in the theme repo.

---

Implement the change described in `org-event-relationships.md` in this repo. Read that file
first — it is an approved plan, already agreed with me, so follow it rather than re-designing.
Also read `CLAUDE.md` and `Relationships.md` before touching code.

**In one sentence:** Organizations already sit under the same creator-role properties on Events
as People do, but the theme filters them out; make them render alongside People in the Event
page's role cloud, and make Organization pages list their Events grouped by role the way Person
pages already do. Add the missing `ceramic:photographer` (512) role while in the same constant.

## Facts you would otherwise have to re-derive — do not re-scan the API for these

- The nine role properties are `ItemRelations::ROLE_PROPS = [501, 502, 518, 511, 514, 503, 500,
  506, 504]`. The Event resource template declares a **tenth**, `512 ceramic:photographer`
  (alt label צלם/ת), which belongs between 502 and 518. Insert it there — that array's order
  drives group order on every page.
- Role labels come from `roleHeading()`: the Event template's alternate label for the property.
  501 = יוצר/ת, 502 = אוצר/ת, 512 = צלם/ת, 518 = תומכ/ת, 511 = שותף/ה, 514 = מפיק/ה,
  503 = מעצב/ת, 500 = יועץ/ת, 506 = יזמ/ת, 504 = תורמ/ת, 13 = שיוך.
- The role properties' data type is custom vocab **#1 "Agents"** = item set **868**, which holds
  1168 People **and** 164 Organizations. That is why an org is a valid value of any role property.
- Live counts of org values under role props: 518 → 42, 511 → 31, 501 → 23, 506 → 1. Properties
  518 and 511 are **org-only** (zero person values), so they render no group at all today.
- `dcterms:relation` (13) is affiliation, **not** a role. 53 of the 58 role-linked orgs have no
  13-linked Event, which is why their pages are empty right now.

## Scope boundaries

- **Do not** touch `view/common/resource-page-block-layout/linked-resources.phtml` or
  `helper/SecondDegreeResources.php`. CLAUDE.md requires discussion before changing that
  behaviour, and those configs are unreachable for templates 15/16/17/18/20 anyway.
- **Do not** change `FacetedSearch::resourcesConnectedToEvents()`'s Organization branch. The only
  edit in that file is adding 512 to its `ROLE_PROPS` mirror.
- No CSS and no JS changes — the existing `.creators-link-cloud` and `.result-subgroup-header`
  markup covers this.
- `view/common/item-person-org.phtml` should need no edit; read it to confirm, and say so rather
  than editing it speculatively.

## Files

`helper/ItemRelations.php` (the substance), `helper/FacetedSearch.php` (one constant),
`view/common/item-event.phtml` and `view/common/item-document.phtml` (`$group['people']` →
`$group['agents']`), plus the documentation updates to `Relationships.md` and `CLAUDE.md` that
the plan's §5 spells out. The doc updates are **required, in the same change** — this is a
data-model change under CLAUDE.md's documentation policy.

## How to work

Show me the diff before applying it — it spans several files.

There is no local Omeka, so lint with the PHP 8.3 binary whose full path is in CLAUDE.md, then
tell me what to check on the live site after I deploy. The plan lists the verification item ids
(1856 is the best single target: orgs under creator, supporter and partner plus a person
photographer) and the pre-change baselines for orgs 1815 and 1745.

Do not use browser tools to verify UI changes.
