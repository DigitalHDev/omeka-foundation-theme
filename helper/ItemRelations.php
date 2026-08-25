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
 *   - Org     -> Event  : Event references the Org via dcterms:relation (13).
 *   - Event   -> Document / Photograph : the Doc/Photo references the Event via
 *                         dcterms:relation (13) [reverse].
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

    /**
     * Creator-role properties connecting an Event to People. Mirrors the
     * Event -> Person property set declared in linked-resources.phtml.
     */
    const ROLE_PROPS = [501, 502, 518, 511, 514, 503, 500, 506, 504];

    /** Literal dcterms:type value that marks a Document as an embedded video. */
    const VIDEO_TYPE = 'וידאו';

    /** @var \Omeka\Api\Manager */
    protected $api;

    /** @var int|null */
    protected $siteId;

    /** @var array Memoized subject queries, keyed by "targetId-templateId-propertyId". */
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
     * @return AbstractResourceEntityRepresentation[]
     */
    public function events(AbstractResourceEntityRepresentation $item)
    {
        $props = $this->templateId($item) === self::TPL_PERSON
            ? self::ROLE_PROPS
            : [self::PROP_RELATION];

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
     * Events a Person took part in, grouped by the relationship (role) under
     * which they are linked — not only as creator. Each group is one
     * creator-role property that yields events, with its events sorted by date.
     * The group label is the property label run through the translation
     * infrastructure, so it appears in the site language.
     *
     * Organizations relate to Events through a single edge (dcterms:relation),
     * so they return one unlabeled group.
     *
     * @return array[] Each: ['label' => string, 'events' => resource[]]
     */
    public function eventsByRole(AbstractResourceEntityRepresentation $item)
    {
        if ($this->templateId($item) !== self::TPL_PERSON) {
            $events = $this->events($item);
            return $events ? [['label' => '', 'events' => $events]] : [];
        }

        $groups = [];
        foreach (self::ROLE_PROPS as $pid) {
            $events = array_values($this->subjects($item->id(), self::TPL_EVENT, $pid));
            if (!$events) {
                continue;
            }
            $this->sortByDate($events);
            $groups[] = [
                'label' => $this->roleHeading($events[0], $pid),
                'events' => $events,
            ];
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
     * People credited on an item, grouped by the creator-role property that
     * links them. Feeds the ".creators-link-cloud" block.
     *
     * The role edges live on the Event (Event -> Person, forward), so a
     * Document/Photograph borrows the groups of the Event it belongs to.
     *
     * @return array[] Each: ['label' => string, 'people' => resource[]]
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
                if ($linked && $this->templateId($linked) === self::TPL_PERSON) {
                    $byProperty[$propertyId][$linked->id()] = $linked;
                }
            }
        }

        $groups = [];
        foreach (self::ROLE_PROPS as $propertyId) {
            if (empty($byProperty[$propertyId])) {
                continue;
            }
            $groups[] = [
                'label' => $this->roleHeading($item, $propertyId),
                'people' => array_values($byProperty[$propertyId]),
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
