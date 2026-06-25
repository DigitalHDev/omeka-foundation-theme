<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Lean, purpose-built graph lookups for the home page only.
 *
 * The home page resolves many random items per request, so it uses these
 * bounded queries rather than SecondDegreeResources (which returns rendered
 * HTML). The traversal RULES are kept identical to SecondDegreeResources and
 * view/common/resource-page-block-layout/linked-resources.phtml — do not fork
 * them (see designs/home-page-plan.md section 2):
 *
 *   - Document / Photograph -> Event / Org / Person  via dcterms:relation (13)
 *   - Event -> Organization                          via dcterms:relation (13)
 *   - Event -> Person                                via ceramic:creator (501)
 *
 * Usage from a template:
 *   $graph = $this->homeGraph();
 *   $pool  = $graph->imagePool(120);
 *   $tile  = $graph->discoverTile(HomeGraph::TPL_PERSON, $pool);
 */
class HomeGraph extends AbstractHelper
{
    const TPL_DOCUMENT = 15;
    const TPL_EVENT = 16;
    const TPL_PERSON = 17;
    const TPL_ORGANIZATION = 18;
    const TPL_PHOTOGRAPH = 20;

    const PROP_RELATION = 13;   // dcterms:relation
    const PROP_CREATOR = 501;   // ceramic:creator

    /** @var \Omeka\Api\Manager */
    protected $api;

    /** @var int|null */
    protected $siteId;

    public function __invoke()
    {
        $view = $this->getView();
        $this->api = $view->api();
        $this->siteId = isset($view->site) ? $view->site->id() : null;
        return $this;
    }

    /**
     * Live total of items for a resource template, scoped to the current site.
     *
     * @param int $templateId
     * @return int
     */
    public function typeCount($templateId)
    {
        $query = ['resource_template_id' => $templateId, 'limit' => 0];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        return $this->api->search('items', $query)->getTotalResults();
    }

    /**
     * A shuffled pool of image-bearing Documents and Photographs, taken from a
     * random window of the collection so the home page varies between loads.
     *
     * @param int $limit Approximate pool size.
     * @return AbstractResourceEntityRepresentation[]
     */
    public function imagePool($limit = 120)
    {
        $perTemplate = (int) ceil($limit / 2);
        $items = array_merge(
            $this->randomWindow(self::TPL_DOCUMENT, $perTemplate),
            $this->randomWindow(self::TPL_PHOTOGRAPH, $perTemplate)
        );

        $withImages = [];
        foreach ($items as $item) {
            if ($item->thumbnailDisplayUrl('large') !== null) {
                $withImages[] = $item;
            }
        }
        shuffle($withImages);
        return $withImages;
    }

    /**
     * Pick a Discover tile (typed resource + image) by walking up from the
     * image-bearing pool, guaranteeing the tile always has an image.
     *
     * @param int $templateId One of TPL_DOCUMENT/EVENT/PERSON/ORGANIZATION.
     * @param AbstractResourceEntityRepresentation[] $pool From imagePool().
     * @param array $usedItemIds Pool item ids already consumed by other tiles.
     * @return array|null ['resource' => rep, 'imageUrl' => string, 'sourceId' => int]
     */
    public function discoverTile($templateId, array $pool, array $usedItemIds = [])
    {
        foreach ($pool as $item) {
            if (in_array($item->id(), $usedItemIds, true)) {
                continue;
            }
            $typed = $this->walkToType($item, $templateId);
            if ($typed) {
                return [
                    'resource' => $typed,
                    'imageUrl' => $item->thumbnailDisplayUrl('large'),
                    'sourceId' => $item->id(),
                ];
            }
        }
        return null;
    }

    /**
     * From an image-bearing Document/Photograph, resolve the typed resource for
     * a Discover category, or null if the chain does not reach that type.
     */
    protected function walkToType(AbstractResourceEntityRepresentation $item, $templateId)
    {
        switch ($templateId) {
            case self::TPL_DOCUMENT:
                return $this->templateId($item) === self::TPL_DOCUMENT ? $item : null;
            case self::TPL_EVENT:
                return $this->relatedByTemplate($item, self::TPL_EVENT);
            case self::TPL_ORGANIZATION:
                // Document may relate directly to an Org, else via its Event.
                $org = $this->relatedByTemplate($item, self::TPL_ORGANIZATION);
                if ($org) {
                    return $org;
                }
                $event = $this->relatedByTemplate($item, self::TPL_EVENT);
                return $event ? $this->relatedByTemplate($event, self::TPL_ORGANIZATION) : null;
            case self::TPL_PERSON:
                $event = $this->relatedByTemplate($item, self::TPL_EVENT);
                return $event ? $this->firstResourceValue($event, 'ceramic:creator', self::TPL_PERSON) : null;
        }
        return null;
    }

    /**
     * Whether a Document/Photograph belongs to an organization in the given
     * item set, by relation directly (Doc -> Org) or via its Event
     * (Doc -> Event -> Org). Implements the "both direct and via Event" rule.
     *
     * @param AbstractResourceEntityRepresentation $item
     * @param int $itemSetId
     * @return bool
     */
    public function belongsToOrgInSet(AbstractResourceEntityRepresentation $item, $itemSetId)
    {
        foreach ($this->relationTargets($item) as $target) {
            $targetTemplate = $this->templateId($target);
            if ($targetTemplate === self::TPL_ORGANIZATION && $this->inItemSet($target, $itemSetId)) {
                return true;
            }
            if ($targetTemplate === self::TPL_EVENT) {
                foreach ($this->relationTargets($target) as $org) {
                    if ($this->templateId($org) === self::TPL_ORGANIZATION
                        && $this->inItemSet($org, $itemSetId)
                    ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    // ---- low-level helpers -------------------------------------------------

    /**
     * A window of items for a template starting at a random offset.
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function randomWindow($templateId, $limit)
    {
        $count = $this->typeCount($templateId);
        if ($count === 0) {
            return [];
        }
        $offset = $count > $limit ? random_int(0, $count - $limit) : 0;
        $query = [
            'resource_template_id' => $templateId,
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        return $this->api->search('items', $query)->getContent();
    }

    protected function templateId(AbstractResourceEntityRepresentation $resource)
    {
        $template = $resource->resourceTemplate();
        return $template ? $template->id() : null;
    }

    /**
     * All resources linked from $resource via dcterms:relation (13).
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function relationTargets(AbstractResourceEntityRepresentation $resource)
    {
        $targets = [];
        foreach ((array) $resource->value('dcterms:relation', ['all' => true]) as $value) {
            $linked = $value->valueResource();
            if ($linked) {
                $targets[] = $linked;
            }
        }
        return $targets;
    }

    /**
     * First dcterms:relation target whose template matches, or null.
     */
    protected function relatedByTemplate(AbstractResourceEntityRepresentation $resource, $templateId)
    {
        foreach ($this->relationTargets($resource) as $target) {
            if ($this->templateId($target) === $templateId) {
                return $target;
            }
        }
        return null;
    }

    /**
     * First value-resource of $term whose template matches, or null.
     */
    protected function firstResourceValue(AbstractResourceEntityRepresentation $resource, $term, $templateId)
    {
        foreach ((array) $resource->value($term, ['all' => true]) as $value) {
            $linked = $value->valueResource();
            if ($linked && $this->templateId($linked) === $templateId) {
                return $linked;
            }
        }
        return null;
    }

    protected function inItemSet(AbstractResourceEntityRepresentation $resource, $itemSetId)
    {
        if (!method_exists($resource, 'itemSets')) {
            return false;
        }
        foreach ($resource->itemSets() as $itemSet) {
            if ($itemSet->id() == $itemSetId) {
                return true;
            }
        }
        return false;
    }
}
