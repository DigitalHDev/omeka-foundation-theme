<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Compose a display title for a resource according to the project's
 * per-type title rules (see designs/home-page-plan.md section 4).
 *
 * Parts are joined with ", ", skipping empty parts.
 *
 * People (17) / Organizations (18) -> their own title.
 * Events (16)                      -> event title (creators, solo/group, org, year).
 * Documents (15)                   -> document type + the related event/org/person.
 *
 * The graph is Event-centric and matches the traversal rules used by
 * SecondDegreeResources and view/common/resource-page-block-layout/linked-resources.phtml:
 *   - Event -> Organization via dcterms:relation (13)
 *   - Event -> Person       via ceramic:creator (501) [participant count uses 501 only]
 *   - Document/Photograph -> Event/Org/Person via dcterms:relation (13)
 */
class ComposeResourceTitle extends AbstractHelper
{
    const TPL_DOCUMENT = 15;
    const TPL_EVENT = 16;
    const TPL_PERSON = 17;
    const TPL_ORGANIZATION = 18;
    const TPL_PHOTOGRAPH = 20;

    const SOLO_SHOW = 'תערוכת יחיד';
    const GROUP_SHOW = 'תערוכה קבוצתית';

    /**
     * Per-request memo caches, keyed by "<resourceName>:<id>" (plus the
     * property term where relevant).
     *
     * The helper instance is shared for the whole request, and every read here
     * is of immutable metadata, so the same resource is only ever resolved
     * once. This matters because a resource reached a second time normally
     * arrives as a *different* representation object - the Event behind two
     * Documents, say - and each object rebuilds its own values() array, paying
     * the property lookups and linked-resource hydrations again
     * (optimization.md 2.4).
     *
     * @var array
     */
    protected $partsCache = [];

    /** @var array */
    protected $templateCache = [];

    /** @var array */
    protected $literalCache = [];

    /** @var array */
    protected $resourceValueCache = [];

    /**
     * @param AbstractResourceEntityRepresentation $resource
     * @return string
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        return $this->join($this->parts($resource));
    }

    /**
     * Build the ordered list of title parts for a resource.
     *
     * @return string[]
     */
    public function parts(AbstractResourceEntityRepresentation $resource)
    {
        $key = $this->cacheKey($resource);
        if (isset($this->partsCache[$key])) {
            return $this->partsCache[$key];
        }
        switch ($this->templateId($resource)) {
            case self::TPL_EVENT:
                $parts = $this->eventParts($resource);
                break;
            case self::TPL_DOCUMENT:
            case self::TPL_PHOTOGRAPH:
                $parts = $this->documentParts($resource);
                break;
            case self::TPL_PERSON:
            case self::TPL_ORGANIZATION:
            default:
                $parts = [$resource->displayTitle()];
        }
        return $this->partsCache[$key] = $parts;
    }

    /**
     * The composed title with its leading *type* part removed, for callers that
     * already display the type separately - the `.item-type-grid` chip printed
     * above the title on an archive-item card.
     *
     * @return string
     */
    public function withoutType(AbstractResourceEntityRepresentation $resource)
    {
        return $this->join($this->partsWithoutType($resource));
    }

    /**
     * parts() minus the leading type part.
     *
     * Only documentParts() prefixes a type. An Event, a Person and an
     * Organization all lead with their own title, so dropping the first part
     * there would lose the name itself - which is why this rule lives in the
     * helper and not at the call site. When a Document carries no dcterms:type
     * the leading part is '' and shifting it off changes nothing.
     *
     * @return string[]
     */
    public function partsWithoutType(AbstractResourceEntityRepresentation $resource)
    {
        $parts = $this->parts($resource);
        $template = $this->templateId($resource);
        if ($template === self::TPL_DOCUMENT || $template === self::TPL_PHOTOGRAPH) {
            array_shift($parts);
        }
        return $parts;
    }

    /**
     * @return string[]
     */
    protected function eventParts(AbstractResourceEntityRepresentation $event)
    {
        $creators = $this->resourceValues($event, 'ceramic:creator');
        $n = count($creators);
        $org = $this->relatedTitle($event, self::TPL_ORGANIZATION);
        $year = $this->literal($event, 'dcterms:date');
        $title = $event->displayTitle();

        if ($n === 1) {
            return [$title, $creators[0]->displayTitle(), self::SOLO_SHOW, $org, $year];
        }
        if ($n === 2) {
            return [$title, $creators[0]->displayTitle(), $creators[1]->displayTitle(), $org, $year];
        }
        if ($n >= 3) {
            return [$title, self::GROUP_SHOW, $org, $year];
        }
        // No creators recorded.
        return [$title, $org, $year];
    }

    /**
     * @return string[]
     */
    protected function documentParts(AbstractResourceEntityRepresentation $doc)
    {
        $docType = $this->literal($doc, 'dcterms:type');
        $rel = $this->firstRelatedResource($doc);
        $relTemplate = $rel ? $this->templateId($rel) : null;

        if ($relTemplate === self::TPL_EVENT) {
            // Through parts(), not eventParts(), so an Event shared by several
            // Documents is composed once per request.
            return array_merge([$docType], $this->parts($rel));
        }
        if ($relTemplate === self::TPL_ORGANIZATION || $relTemplate === self::TPL_PERSON) {
            return [$docType, $doc->displayTitle(), $rel->displayTitle(), $this->literal($doc, 'dcterms:date')];
        }
        return [$docType, $doc->displayTitle(), $this->literal($doc, 'dcterms:date')];
    }

    /**
     * Join non-empty, trimmed parts with ", ".
     *
     * @param string[] $parts
     * @return string
     */
    protected function join(array $parts)
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode(', ', $clean);
    }

    /** Memo key for a resource: unique per entity within the request. */
    protected function cacheKey(AbstractResourceEntityRepresentation $resource)
    {
        return $resource->resourceName() . ':' . $resource->id();
    }

    protected function templateId(AbstractResourceEntityRepresentation $resource)
    {
        $key = $this->cacheKey($resource);
        if (!array_key_exists($key, $this->templateCache)) {
            $template = $resource->resourceTemplate();
            $this->templateCache[$key] = $template ? $template->id() : null;
        }
        return $this->templateCache[$key];
    }

    /**
     * First literal value of a property as a string, or '' if none.
     */
    protected function literal(AbstractResourceEntityRepresentation $resource, $term)
    {
        $key = $this->cacheKey($resource) . '|' . $term;
        if (!array_key_exists($key, $this->literalCache)) {
            $value = $resource->value($term);
            $this->literalCache[$key] = $value ? trim((string) $value) : '';
        }
        return $this->literalCache[$key];
    }

    /**
     * Linked resource values of a property (the value resources only).
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function resourceValues(AbstractResourceEntityRepresentation $resource, $term)
    {
        $key = $this->cacheKey($resource) . '|' . $term;
        if (isset($this->resourceValueCache[$key])) {
            return $this->resourceValueCache[$key];
        }
        $resources = [];
        foreach ((array) $resource->value($term, ['all' => true]) as $value) {
            $linked = $value->valueResource();
            if ($linked) {
                $resources[] = $linked;
            }
        }
        return $this->resourceValueCache[$key] = $resources;
    }

    /**
     * First resource linked via dcterms:relation (13), any template.
     */
    protected function firstRelatedResource(AbstractResourceEntityRepresentation $resource)
    {
        $related = $this->resourceValues($resource, 'dcterms:relation');
        return $related ? $related[0] : null;
    }

    /**
     * Display title of the first dcterms:relation target matching a template, or ''.
     */
    protected function relatedTitle(AbstractResourceEntityRepresentation $resource, $templateId)
    {
        foreach ($this->resourceValues($resource, 'dcterms:relation') as $linked) {
            if ($this->templateId($linked) === $templateId) {
                return $linked->displayTitle();
            }
        }
        return '';
    }
}
