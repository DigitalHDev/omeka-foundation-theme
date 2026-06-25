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
        switch ($this->templateId($resource)) {
            case self::TPL_PERSON:
            case self::TPL_ORGANIZATION:
                return [$resource->displayTitle()];
            case self::TPL_EVENT:
                return $this->eventParts($resource);
            case self::TPL_DOCUMENT:
            case self::TPL_PHOTOGRAPH:
                return $this->documentParts($resource);
            default:
                return [$resource->displayTitle()];
        }
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
            return array_merge([$docType], $this->eventParts($rel));
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

    protected function templateId(AbstractResourceEntityRepresentation $resource)
    {
        $template = $resource->resourceTemplate();
        return $template ? $template->id() : null;
    }

    /**
     * First literal value of a property as a string, or '' if none.
     */
    protected function literal(AbstractResourceEntityRepresentation $resource, $term)
    {
        $value = $resource->value($term);
        return $value ? trim((string) $value) : '';
    }

    /**
     * Linked resource values of a property (the value resources only).
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function resourceValues(AbstractResourceEntityRepresentation $resource, $term)
    {
        $resources = [];
        foreach ((array) $resource->value($term, ['all' => true]) as $value) {
            $linked = $value->valueResource();
            if ($linked) {
                $resources[] = $linked;
            }
        }
        return $resources;
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
