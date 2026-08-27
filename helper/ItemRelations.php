<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Connection lookups for the redesigned item pages: Person / Organization
 * (Designs/item.html) and Document / Photograph / Event (Designs/item-child.html).
 * Returns resource/media objects (not rendered HTML) so the templates can build
 * the bespoke "related items" grid and the media gallery.
 *
 * The traversal RULES are kept identical to SecondDegreeResources and
 * view/common/resource-page-block-layout/linked-resources.phtml — this helper
 * does NOT fork them (see the SecondDegreeResources usage policy in CLAUDE.md):
 *
 *   - Person  -> Event  : Event references the Person via a creator-role
 *                         property (ceramic:creator 501 and its sibling roles).
 *   - Org     -> Event  : Event references the Org via dcterms:relation (13)
 *                         [affiliation/host] and/or via the same creator-role
 *                         properties [credits] — an Organization is a valid
 *                         value of any role property (see ROLE_PROPS).
 *   - Event   -> Document / Photograph : the Doc/Photo references the Event via
 *                         dcterms:relation (13) [reverse].
 *   - Org     -> Document (own publications, Organization pages only) : the
 *                         Document credits the Org through one of
 *                         DIRECT_DOC_PROPS [reverse]. A first-degree edge, not
 *                         a traversal through an Event.
 *   - Doc/Photo -> Event, Event -> Org : forward dcterms:relation (13); used for
 *                         the item page's "back to" parent bar.
 *
 * Usage from a template:
 *   $relations = $this->ItemRelations();
 *   $events    = $relations->events($item);
 *   $docs      = $relations->relatedByTemplate($events, ItemRelations::TPL_DOCUMENT);
 *   $tiles     = $relations->galleryTiles($item);
 */
class ItemRelations extends AbstractHelper
{
    const TPL_DOCUMENT = 15;
    const TPL_EVENT = 16;
    const TPL_PERSON = 17;
    const TPL_ORGANIZATION = 18;
    const TPL_PHOTOGRAPH = 20;

    const PROP_RELATION = 13;   // dcterms:relation
    const PROP_PUBLISHER = 515; // ceramic:publisher

    /**
     * Creator-role properties connecting an Event to an Agent — a Person OR an
     * Organization. Their data type is custom vocab #1 ("Agents" = item set
     * 868), which holds both templates, so either may be the value of any of
     * them. This constant is the source of truth for the role set
     * (Relationships.md §2); the list in linked-resources.phtml is legacy and
     * unreachable for templates 15/16/17/18/20.
     *
     * The order below is the Event template's own declaration order and drives
     * group order on every page that renders roles.
     */
    const ROLE_PROPS = [501, 502, 512, 518, 511, 514, 503, 500, 506, 504];

    /**
     * Document -> Agent *credit* properties, used only to list an Organization's
     * own publications (Relationships.md §3). This is a first-degree edge and is
     * NOT part of the Event-mediated traversal above; it is read exclusively by
     * directDocuments(), which is Organization-only.
     *
     * Site-wide only 13, 515 and 501 currently carry Organization values; the
     * remaining role properties are person-only in the present data but are
     * listed so the set stays correct if that changes. They cost nothing —
     * subjectsAny() ORs the whole set into one search.
     *
     * Deliberately EXCLUDES 522 `ארגונים מוזכרים` and 523 `א/נשים מוזכרים`:
     * being mentioned in a document is not authorship of it, and folding those
     * in would list every venue named in a catalogue among that venue's own
     * publications.
     */
    const DIRECT_DOC_PROPS = [self::PROP_RELATION, self::PROP_PUBLISHER, 519, 505, 512, 503, 501];

    /** Literal dcterms:type value that marks a Document as an embedded video. */
    const VIDEO_TYPE = 'וידאו';

    /** @var \Omeka\Api\Manager */
    protected $api;

    /** @var int|null */
    protected $siteId;

    /**
     * @var array Memoized subject queries. Keys are "targetId-templateId-propertyId"
     * for subjects() and "targetId-templateId-any:id,id,…" for subjectsAny().
     */
    protected $subjectCache = [];

    public function __invoke()
    {
        $view = $this->getView();
        $this->api = $view->api();
        $this->siteId = isset($view->site) ? $view->site->id() : null;
        return $this;
    }

    /**
     * First-degree Events connected to a Person or Organization, de-duplicated
     * and sorted by dcterms:date (ascending; undated last).
     *
     * A Person is reached through the creator-role properties only. An
     * Organization is reached through dcterms:relation (13, affiliation/host)
     * AND the role properties (credits) — most role-linked Orgs have no
     * property-13 Event at all.
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    public function events(AbstractResourceEntityRepresentation $item)
    {
        $templateId = $this->templateId($item);
        if ($templateId === self::TPL_PERSON) {
            $props = self::ROLE_PROPS;
        } elseif ($templateId === self::TPL_ORGANIZATION) {
            $props = array_merge([self::PROP_RELATION], self::ROLE_PROPS);
        } else {
            $props = [self::PROP_RELATION];
        }

        $events = [];
        foreach ($props as $pid) {
            foreach ($this->subjects($item->id(), self::TPL_EVENT, $pid) as $event) {
                $events[$event->id()] = $event;
            }
        }
        $events = array_values($events);
        $this->sortByDate($events);
        return $events;
    }

    /**
     * Second-degree resources of a given template referenced by the supplied
     * Events via dcterms:relation, de-duplicated and sorted by date.
     *
     * @param AbstractResourceEntityRepresentation[] $events
     * @param int $templateId One of TPL_DOCUMENT / TPL_PHOTOGRAPH.
     * @return AbstractResourceEntityRepresentation[]
     */
    public function relatedByTemplate(array $events, $templateId)
    {
        $resources = [];
        foreach ($events as $event) {
            foreach ($this->subjects($event->id(), $templateId, self::PROP_RELATION) as $resource) {
                $resources[$resource->id()] = $resource;
            }
        }
        $resources = array_values($resources);
        $this->sortByDate($resources);
        return $resources;
    }

    /**
     * Events an Agent (Person or Organization) took part in, grouped by the
     * relationship (role) under which they are linked — not only as creator.
     * Each group is one creator-role property that yields events, with its
     * events sorted by date. The group label is the property's Event-template
     * alternate label, so it appears in the site language.
     *
     * An Organization additionally relates to Events through dcterms:relation
     * (13), which is affiliation rather than a role: those Events are returned
     * as one leading *unlabeled* group. Role groups win — an Event reachable
     * both ways is listed under its role only, never twice.
     *
     * Any other template keeps the single unlabeled group from events().
     *
     * @return array[] Each: ['label' => string, 'events' => resource[]]
     */
    public function eventsByRole(AbstractResourceEntityRepresentation $item)
    {
        $templateId = $this->templateId($item);
        if (!in_array($templateId, [self::TPL_PERSON, self::TPL_ORGANIZATION], true)) {
            $events = $this->events($item);
            return $events ? [['label' => '', 'events' => $events]] : [];
        }

        $groups = [];
        $claimed = [];
        foreach (self::ROLE_PROPS as $pid) {
            $events = array_values($this->subjects($item->id(), self::TPL_EVENT, $pid));
            if (!$events) {
                continue;
            }
            $this->sortByDate($events);
            foreach ($events as $event) {
                $claimed[$event->id()] = true;
            }
            $groups[] = [
                'label' => $this->roleHeading($events[0], $pid),
                'events' => $events,
            ];
        }

        if ($templateId === self::TPL_ORGANIZATION) {
            $affiliated = [];
            foreach ($this->subjects($item->id(), self::TPL_EVENT, self::PROP_RELATION) as $event) {
                if (!isset($claimed[$event->id()])) {
                    $affiliated[] = $event;
                }
            }
            if ($affiliated) {
                $this->sortByDate($affiliated);
                array_unshift($groups, ['label' => '', 'events' => $affiliated]);
            }
        }

        return $groups;
    }

    /**
     * An Organization's OWN publications: Documents that credit this Org
     * directly through one of DIRECT_DOC_PROPS, deduplicated and date-sorted.
     *
     * First degree — no Event is involved. Returns [] for any other template:
     * a Person page's "פרסומים" section is the Event-mediated list produced by
     * relatedByTemplate() and must not change (Relationships.md §4).
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    public function directDocuments(AbstractResourceEntityRepresentation $item)
    {
        if ($this->templateId($item) !== self::TPL_ORGANIZATION) {
            return [];
        }
        $documents = array_values($this->subjectsAny(
            $item->id(),
            self::TPL_DOCUMENT,
            self::DIRECT_DOC_PROPS
        ));
        $this->sortByDate($documents);
        return $documents;
    }

    /**
     * An Organization's second-degree Documents — those referencing its Events —
     * grouped by the relationship under which the Org took part in the Event, so
     * the headings match the "אירועים" section above them verbatim. The
     * dcterms:relation (13) affiliation group leads and stays unlabeled, exactly
     * as in eventsByRole().
     *
     * Two levels of de-duplication:
     *  - across groups: first group wins, so a Document is never listed twice
     *    (mirrors eventsByRole()'s rule for Events);
     *  - against $excludeIds: pass directDocuments()'s ids so a Document
     *    carrying dcterms:relation to BOTH the Org and one of its Events is
     *    listed under "פרסומים" only.
     *
     * Adds no queries over the flat relatedByTemplate() call it replaces —
     * subjects() memoizes per (event, template, property) and these are the same
     * Events, visited per group instead of as one list.
     *
     * Organization-only; returns [] for any other template.
     *
     * @param int[] $excludeIds Document ids already rendered elsewhere.
     * @return array[] Each: ['label' => string, 'documents' => resource[]]
     */
    public function docsFromEventsByRole(AbstractResourceEntityRepresentation $item, array $excludeIds = [])
    {
        if ($this->templateId($item) !== self::TPL_ORGANIZATION) {
            return [];
        }
        $seen = array_fill_keys($excludeIds, true);
        $groups = [];
        foreach ($this->eventsByRole($item) as $group) {
            $documents = [];
            foreach ($this->relatedByTemplate($group['events'], self::TPL_DOCUMENT) as $document) {
                if (isset($seen[$document->id()])) {
                    continue;
                }
                $seen[$document->id()] = true;
                $documents[] = $document;
            }
            if ($documents) {
                $groups[] = ['label' => $group['label'], 'documents' => $documents];
            }
        }
        return $groups;
    }

    /**
     * Build the "צילומי הצבה" gallery tiles from the Documents and Photographs
     * connected to this Person/Org (second degree, via their Events).
     *
     *  - A Document/Photograph whose dcterms:type is "וידאו" yields one video
     *    tile played from its bibo:uri (YouTube).
     *  - Otherwise every image media file yields one tile. When a source has
     *    more than one media file, the media's own title is appended to the
     *    composed document caption so the tiles are distinguishable.
     *
     * @return array[] Each: ['kind'=>'image'|'video', 'thumb'=>str, 'full'=>str,
     *                        'embed'=>str|null, 'caption'=>str]
     */
    public function galleryTiles(AbstractResourceEntityRepresentation $item)
    {
        $events = $this->events($item);
        $sources = array_merge(
            $this->relatedByTemplate($events, self::TPL_DOCUMENT),
            $this->relatedByTemplate($events, self::TPL_PHOTOGRAPH)
        );

        $tiles = [];
        foreach ($sources as $source) {
            $tiles = array_merge($tiles, $this->tilesForSource($source));
        }
        return $tiles;
    }

    /**
     * Gallery tiles for a single Document/Photograph's own media, in the same
     * shape as galleryTiles(). Used by the Document item page, whose results
     * grid shows the item's own files rather than second-degree resources.
     *
     * @return array[] See galleryTiles().
     */
    public function mediaTiles(AbstractResourceEntityRepresentation $resource)
    {
        return $this->tilesForSource($resource);
    }

    /**
     * The resource shown in the item page's "back to" parent bar.
     *
     *  - Document / Photograph (15/20): the resource it references via
     *    dcterms:relation (13). An Event is preferred, since that is the
     *    canonical edge; a directly-referenced Org or Person is accepted as a
     *    fallback (both occur in the data — see Relationships.md §3).
     *  - Event (16): the Organization it references via dcterms:relation (13).
     *
     * @return AbstractResourceEntityRepresentation|null
     */
    public function parentResource(AbstractResourceEntityRepresentation $item)
    {
        $templateId = $this->templateId($item);
        if (!in_array($templateId, [self::TPL_DOCUMENT, self::TPL_PHOTOGRAPH, self::TPL_EVENT], true)) {
            return null;
        }

        $wanted = $templateId === self::TPL_EVENT ? self::TPL_ORGANIZATION : self::TPL_EVENT;
        $fallback = null;
        foreach ((array) $item->value('dcterms:relation', ['all' => true]) as $value) {
            $linked = $value->valueResource();
            if (!$linked) {
                continue;
            }
            if ($this->templateId($linked) === $wanted) {
                return $linked;
            }
            if (!$fallback) {
                $fallback = $linked;
            }
        }
        return $templateId === self::TPL_EVENT ? null : $fallback;
    }

    /**
     * Agents — People and Organizations — credited on an item, grouped by the
     * creator-role property that links them. Feeds the ".creators-link-cloud"
     * block. Within a group, People come first, then Organizations, each in the
     * order the values appear on the Event; the two are not distinguished
     * visually.
     *
     * The role edges live on the Event (Event -> Agent, forward), so a
     * Document/Photograph borrows the groups of the Event it belongs to.
     *
     * @return array[] Each: ['label' => string, 'agents' => resource[]]
     */
    public function creatorGroups(AbstractResourceEntityRepresentation $item)
    {
        if ($this->templateId($item) !== self::TPL_EVENT) {
            $parent = $this->parentResource($item);
            return $parent && $this->templateId($parent) === self::TPL_EVENT
                ? $this->creatorGroups($parent)
                : [];
        }

        $byProperty = [];
        foreach ($item->values() as $info) {
            $propertyId = $info['property']->id();
            if (!in_array($propertyId, self::ROLE_PROPS, true)) {
                continue;
            }
            foreach ($info['values'] as $value) {
                $linked = $value->valueResource();
                $linkedTemplate = $linked ? $this->templateId($linked) : null;
                if ($linkedTemplate === self::TPL_PERSON) {
                    $byProperty[$propertyId]['people'][$linked->id()] = $linked;
                } elseif ($linkedTemplate === self::TPL_ORGANIZATION) {
                    $byProperty[$propertyId]['orgs'][$linked->id()] = $linked;
                }
            }
        }

        $groups = [];
        foreach (self::ROLE_PROPS as $propertyId) {
            if (empty($byProperty[$propertyId])) {
                continue;
            }
            $agents = array_merge(
                array_values($byProperty[$propertyId]['people'] ?? []),
                array_values($byProperty[$propertyId]['orgs'] ?? [])
            );
            $groups[] = [
                'label' => $this->roleHeading($item, $propertyId),
                'agents' => $agents,
            ];
        }
        return $groups;
    }

    /**
     * Documents and Photographs referencing the supplied Events, merged into one
     * date-sorted list. Both templates are always traversed as a pair.
     *
     * @param AbstractResourceEntityRepresentation[] $events
     * @return AbstractResourceEntityRepresentation[]
     */
    public function relatedDocsAndPhotos(array $events)
    {
        $resources = [];
        foreach ([self::TPL_DOCUMENT, self::TPL_PHOTOGRAPH] as $templateId) {
            foreach ($this->relatedByTemplate($events, $templateId) as $resource) {
                $resources[$resource->id()] = $resource;
            }
        }
        $resources = array_values($resources);
        $this->sortByDate($resources);
        return $resources;
    }

    /**
     * Target of the hero thumbnail's "open file" affordance: the original media
     * file, or the YouTube link for a video Document. Null when the item carries
     * neither.
     *
     * @return array|null ['url' => string, 'label' => string]
     */
    public function primaryFileLink(AbstractResourceEntityRepresentation $resource)
    {
        if ($this->isVideo($resource)) {
            $uri = $this->uriValue($resource, 'bibo:uri');
            return $uri === '' ? null : ['url' => $uri, 'label' => 'צפייה בסרטון'];
        }
        foreach ($resource->media() as $media) {
            $url = $media->originalUrl();
            if ($url) {
                return ['url' => $url, 'label' => 'פתיחת קובץ'];
            }
        }
        return null;
    }

    /**
     * Flatten a resource into the column data used by an "archive-item" card in
     * the related-items grid.
     *
     * Events have no media of their own, so their card visual is borrowed from a
     * Document connected to the Event (see eventThumbnail()). The pick is random,
     * so the image varies between requests.
     *
     * @return array ['url','thumb','type','name','artist','date','medium']
     */
    public function cardData(AbstractResourceEntityRepresentation $resource)
    {
        $template = $resource->resourceTemplate();
        $type = $this->literal($resource, 'dcterms:type');
        if ($type === '' && $template) {
            $type = $template->label();
        }
        $thumb = $resource->thumbnailDisplayUrl('medium')
            ?: $resource->thumbnailDisplayUrl('large');
        if (!$thumb && $this->templateId($resource) === self::TPL_EVENT) {
            $thumb = $this->eventThumbnail($resource);
        }
        return [
            'url' => $resource->url(),
            'thumb' => $thumb,
            'type' => $type,
            'name' => $resource->displayTitle(),
            'artist' => $this->creatorNames($resource),
            'date' => $this->literal($resource, 'dcterms:date'),
            'medium' => $this->literal($resource, 'dcterms:medium'),
        ];
    }

    /**
     * A thumbnail for an Event card, taken from the media of a Document
     * connected to the Event (Event <- Document via dcterms:relation). One image
     * is chosen at random across all qualifying media so the card image varies
     * between requests. Returns '' when no related Document carries an image.
     */
    public function eventThumbnail(AbstractResourceEntityRepresentation $event)
    {
        $thumbs = [];
        foreach ($this->relatedByTemplate([$event], self::TPL_DOCUMENT) as $document) {
            foreach ($document->media() as $media) {
                if ($media->hasThumbnails()) {
                    $thumbs[] = $media->thumbnailUrl('medium');
                }
            }
        }
        return $thumbs ? $thumbs[array_rand($thumbs)] : '';
    }

    // ---- low-level helpers -------------------------------------------------

    /**
     * Tiles for one Document/Photograph. Shared by galleryTiles() and
     * mediaTiles() so the video/multi-media caption rules stay in one place.
     *
     * @return array[]
     */
    protected function tilesForSource(AbstractResourceEntityRepresentation $source)
    {
        $caption = $this->getView()->ComposeResourceTitle($source);

        if ($this->isVideo($source)) {
            $embed = $this->youtubeEmbed($this->uriValue($source, 'bibo:uri'));
            if (!$embed) {
                return [];
            }
            return [[
                'kind' => 'video',
                'thumb' => $source->thumbnailDisplayUrl('large')
                    ?: $source->thumbnailDisplayUrl('medium'),
                'full' => null,
                'embed' => $embed,
                'caption' => $caption,
            ]];
        }

        $imageMedia = [];
        foreach ($source->media() as $media) {
            if ($media->hasThumbnails()) {
                $imageMedia[] = $media;
            }
        }
        $multi = count($imageMedia) > 1;

        $tiles = [];
        foreach ($imageMedia as $media) {
            $mediaCaption = $caption;
            if ($multi) {
                $title = trim((string) $media->displayTitle(''));
                if ($title !== '') {
                    $mediaCaption = $caption === '' ? $title : $caption . ', ' . $title;
                }
            }
            $tiles[] = [
                'kind' => 'image',
                'thumb' => $media->thumbnailUrl('large'),
                'full' => $media->originalUrl() ?: $media->thumbnailUrl('large'),
                'embed' => null,
                'caption' => $mediaCaption,
            ];
        }
        return $tiles;
    }

    /**
     * Items of a template that reference $targetId through one property, scoped
     * to the current site. Memoized.
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function subjects($targetId, $templateId, $propertyId)
    {
        $key = $targetId . '-' . $templateId . '-' . $propertyId;
        if (isset($this->subjectCache[$key])) {
            return $this->subjectCache[$key];
        }
        $query = [
            'property' => [[
                'property' => $propertyId,
                'type' => 'res',
                'text' => $targetId,
            ]],
            'resource_template_id' => $templateId,
        ];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        $resources = $this->api->search('items', $query)->getContent();
        $this->subjectCache[$key] = $resources;
        return $resources;
    }

    /**
     * As subjects(), but matching ANY of several properties in a single search
     * (Omeka joins consecutive property rows with OR when they carry
     * joiner => 'or'). One query instead of one per property. Memoized.
     *
     * @param int[] $propertyIds
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function subjectsAny($targetId, $templateId, array $propertyIds)
    {
        $key = $targetId . '-' . $templateId . '-any:' . implode(',', $propertyIds);
        if (isset($this->subjectCache[$key])) {
            return $this->subjectCache[$key];
        }
        $property = [];
        foreach (array_values($propertyIds) as $i => $propertyId) {
            $row = [
                'property' => $propertyId,
                'type' => 'res',
                'text' => $targetId,
            ];
            if ($i > 0) {
                $row['joiner'] = 'or';
            }
            $property[] = $row;
        }
        $query = [
            'property' => $property,
            'resource_template_id' => $templateId,
        ];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        $resources = $this->api->search('items', $query)->getContent();
        $this->subjectCache[$key] = $resources;
        return $resources;
    }

    protected function templateId(AbstractResourceEntityRepresentation $resource)
    {
        $template = $resource->resourceTemplate();
        return $template ? $template->id() : null;
    }

    /**
     * First literal value of a property as a trimmed string, or '' if none.
     */
    protected function literal(AbstractResourceEntityRepresentation $resource, $term)
    {
        $value = $resource->value($term);
        return $value ? trim((string) $value) : '';
    }

    /**
     * Comma-joined display titles of ceramic:creator value-resources, or ''.
     */
    protected function creatorNames(AbstractResourceEntityRepresentation $resource)
    {
        $names = [];
        foreach ((array) $resource->value('ceramic:creator', ['all' => true]) as $value) {
            $linked = $value->valueResource();
            if ($linked) {
                $names[] = $linked->displayTitle();
            }
        }
        return implode(', ', $names);
    }

    protected function isVideo(AbstractResourceEntityRepresentation $resource)
    {
        $type = $resource->value('dcterms:type');
        return $type && trim((string) $type) === self::VIDEO_TYPE;
    }

    /**
     * URI string of the first value of $term, or '' if none.
     */
    protected function uriValue(AbstractResourceEntityRepresentation $resource, $term)
    {
        $value = $resource->value($term);
        if (!$value) {
            return '';
        }
        $uri = $value->uri();
        return (string) ($uri !== null && $uri !== '' ? $uri : $value->value());
    }

    /**
     * Build a YouTube embed URL from a watch / youtu.be / embed link, or '' if
     * the URL is not recognizably YouTube.
     */
    protected function youtubeEmbed($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $id = '';
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|v/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            $id = $m[1];
        }
        return $id === '' ? '' : 'https://www.youtube.com/embed/' . $id;
    }

    /**
     * Label for a relationship (role) property, used as the events sub-heading.
     * Matches the pre-redesign behavior of subjectValues(): the role name is the
     * resource-template alternate label set on the Event template for that
     * property (already localized, e.g. "יוצר/ת"), falling back to the global
     * property label. NOT gettext — these labels are not in the message catalog.
     */
    protected function roleHeading(AbstractResourceEntityRepresentation $event, $propertyId)
    {
        try {
            $template = $event->resourceTemplate();
            if ($template) {
                $templateProperty = $template->resourceTemplateProperty($propertyId);
                if ($templateProperty && $templateProperty->alternateLabel()) {
                    return (string) $templateProperty->alternateLabel();
                }
            }
        } catch (\Exception $e) {
            // fall through to the global property label
        }
        try {
            return (string) $this->api->read('properties', ['id' => $propertyId])
                ->getContent()->label();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Sort resources by dcterms:date ascending; undated entries sort last by
     * title. Mirrors the ordering used by linked-resources-sorted.phtml.
     */
    protected function sortByDate(array &$resources)
    {
        usort($resources, function ($a, $b) {
            $da = $this->dateKey($a);
            $db = $this->dateKey($b);
            if ($da === null && $db === null) {
                return strcmp($a->displayTitle(), $b->displayTitle());
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }
            return $da <=> $db;
        });
    }

    /**
     * @return int|null Sortable timestamp, or null when undated/unparseable.
     */
    protected function dateKey(AbstractResourceEntityRepresentation $resource)
    {
        $value = $resource->value('dcterms:date');
        if (!$value) {
            return null;
        }
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }
        if (preg_match('/^\d{4}$/', $string)) {
            return mktime(0, 0, 0, 1, 1, (int) $string);
        }
        $timestamp = strtotime($string);
        return $timestamp === false ? null : $timestamp;
    }
}
