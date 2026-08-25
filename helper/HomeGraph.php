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
 *   $graph    = $this->homeGraph();
 *   $pool     = $graph->imagePool(120);
 *   $tile     = $graph->discoverTile(HomeGraph::TPL_PERSON, $pool);
 *   $selected = $graph->orgDescendantPool(4211, 32);
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

    /**
     * Bounds for orgDescendantPool(). Each reverse lookup is a single-target
     * query (one indexed `res` clause), so these cap the number of round trips.
     */
    const SAMPLE_ORGS = 8;
    const EVENTS_PER_ORG = 3;
    /** Total Events to draw on, shared out when the item set holds fewer than SAMPLE_ORGS orgs. */
    const EVENT_BUDGET = 24;
    const HYDRATE_CHUNK = 40;
    /** Hard cap on items hydrated while looking for thumbnails, so a media-poor branch cannot stall the page. */
    const CANDIDATE_CAP = 120;

    /** @var \Omeka\Api\Manager */
    protected $api;

    /** @var int|null */
    protected $siteId;

    /** @var float */
    protected $started;

    /** @var array Timing marks: [seconds, label, count|null]. Read via marks(). */
    protected $marks = [];

    public function __invoke()
    {
        $view = $this->getView();
        $this->api = $view->api();
        $this->siteId = isset($view->site) ? $view->site->id() : null;
        $this->started = microtime(true);
        return $this;
    }

    /**
     * Elapsed-time marks recorded so far, for the home page's ?hgdebug output.
     *
     * @return array[] Each: [seconds, label, count|null]
     */
    public function marks()
    {
        return $this->marks;
    }

    /**
     * Absolute time the marks are relative to, so PerfProbe can merge them into
     * a single request-relative timeline.
     *
     * @return float
     */
    public function startedAt()
    {
        return $this->started;
    }

    protected function mark($label, $count = null)
    {
        $this->marks[] = [round(microtime(true) - $this->started, 3), $label, $count];
    }

    /**
     * Live total of items for a resource template, scoped to the current site.
     *
     * @param int $templateId
     * @param array $extra Additional query params (e.g. ['has_media' => 1]).
     * @return int
     */
    public function typeCount($templateId, array $extra = [])
    {
        $query = $extra + ['resource_template_id' => $templateId, 'limit' => 0];
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
        $this->mark('imagePool: windows read', count($items));

        $withImages = [];
        foreach ($items as $item) {
            if ($item->thumbnailDisplayUrl('large') !== null) {
                $withImages[] = $item;
            }
        }
        shuffle($withImages);
        $this->mark('imagePool: thumbnails checked', count($withImages));
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
     * Image-bearing Documents and Photographs that are grandchildren of the
     * Organizations in an item set: Org <- Event <- Document/Photograph. Both
     * edges are dcterms:relation (13) pointing "up", so both hops are reverse
     * (subject) lookups — the same rule as ItemRelations::events() and
     * ItemRelations::relatedByTemplate().
     *
     * Organizations and Events are sampled per request so the section varies
     * between loads. Every reverse lookup targets a single id (one indexed
     * `res` clause, the same query shape as ItemRelations::subjects()).
     *
     * Results are collected into one bucket per Organization and then
     * interleaved round-robin, so the first screenful shows as many different
     * Organizations as possible rather than everything from whichever org
     * happens to have the most Events.
     *
     * @param int $itemSetId Item set holding the Organizations.
     * @param int $limit Number of image-bearing items wanted.
     * @return AbstractResourceEntityRepresentation[]
     */
    public function orgDescendantPool($itemSetId, $limit = 32)
    {
        $orgIds = $this->sample($this->orgIdsInSet($itemSetId), self::SAMPLE_ORGS);
        $this->mark('orgs sampled', count($orgIds));
        if (!$orgIds) {
            return [];
        }

        // Oversample per org so a bucket can still fill the round-robin after
        // duplicates and thumbnail-less items drop out.
        $perOrg = (int) ceil($limit / count($orgIds)) * 2;
        $eventsPerOrg = max(self::EVENTS_PER_ORG, (int) ceil(self::EVENT_BUDGET / count($orgIds)));

        $buckets = [];
        $queried = 0;
        foreach ($orgIds as $orgId) {
            $eventIds = $this->sample(
                $this->subjectIdsFor($orgId, self::TPL_EVENT, self::PROP_RELATION),
                $eventsPerOrg
            );
            $ids = [];
            foreach ($eventIds as $eventId) {
                foreach ([self::TPL_DOCUMENT, self::TPL_PHOTOGRAPH] as $tpl) {
                    foreach ($this->subjectIdsFor($eventId, $tpl, self::PROP_RELATION, ['has_media' => 1]) as $id) {
                        $ids[$id] = true;
                    }
                    $queried++;
                }
                if (count($ids) >= $perOrg) {
                    break;
                }
            }
            if ($ids) {
                $buckets[] = $this->sample(array_keys($ids), $perOrg);
            }
        }
        $this->mark('orgs with items (queries)', count($buckets) . '/' . $queried);
        if (!$buckets) {
            return [];
        }

        $ordered = array_slice($this->roundRobin($buckets), 0, self::CANDIDATE_CAP);
        $this->mark('candidates interleaved', count($ordered));

        $selected = $this->hydrateInOrder($ordered, $limit);
        $this->mark('selected items hydrated', count($selected));
        return $selected;
    }

    // ---- low-level helpers -------------------------------------------------

    /** Ids of the Organizations in an item set, scoped to the current site. */
    protected function orgIdsInSet($itemSetId)
    {
        $query = [
            'item_set_id' => $itemSetId,
            'resource_template_id' => self::TPL_ORGANIZATION,
        ];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        return $this->scalarIds($query);
    }

    /**
     * Ids of items of a template that reference one target through one property.
     * Single-target so the values-table index is usable — same query shape as
     * ItemRelations::subjects().
     *
     * @param array $extra Additional query params (e.g. ['has_media' => 1]).
     * @return int[]
     */
    protected function subjectIdsFor($targetId, $templateId, $propertyId, array $extra = [])
    {
        $query = $extra + ['resource_template_id' => $templateId];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        $query['property'][] = [
            'property' => $propertyId,
            'type' => 'res',
            'text' => $targetId,
        ];
        return $this->scalarIds($query);
    }

    /**
     * Interleave buckets one entry at a time (bucket order shuffled per call),
     * so consecutive positions come from different buckets. Ids appearing in
     * more than one bucket keep their earliest position.
     *
     * @param array[] $buckets
     * @return int[]
     */
    protected function roundRobin(array $buckets)
    {
        shuffle($buckets);
        $out = [];
        for ($i = 0, $added = true; $added; $i++) {
            $added = false;
            foreach ($buckets as $bucket) {
                if (isset($bucket[$i])) {
                    $out[] = $bucket[$i];
                    $added = true;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Read items by id, then return them in the order of $ids, keeping only
     * those with a large thumbnail, up to $limit.
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function hydrateInOrder(array $ids, $limit)
    {
        $byId = [];
        foreach (array_chunk($ids, self::HYDRATE_CHUNK) as $chunk) {
            $query = ['id' => $chunk];
            if ($this->siteId) {
                $query['site_id'] = $this->siteId;
            }
            foreach ($this->api->search('items', $query)->getContent() as $item) {
                $byId[$item->id()] = $item;
            }
        }

        $items = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id]) || $byId[$id]->thumbnailDisplayUrl('large') === null) {
                continue;
            }
            $items[] = $byId[$id];
            if (count($items) >= $limit) {
                break;
            }
        }
        return $items;
    }

    /** Random subset of at most $max ids. */
    protected function sample(array $ids, $max)
    {
        shuffle($ids);
        return array_slice($ids, 0, $max);
    }

    /**
     * Run an id-only (scalar) item search and return a flat list of ids.
     *
     * @return int[]
     */
    protected function scalarIds(array $query)
    {
        $query['return_scalar'] = 'id';
        return array_values(array_map('intval', $this->api->search('items', $query)->getContent()));
    }

    /**
     * A window of items for a template starting at a random offset.
     *
     * @return AbstractResourceEntityRepresentation[]
     */
    protected function randomWindow($templateId, $limit)
    {
        $extra = ['has_media' => 1];
        $count = $this->typeCount($templateId, $extra);
        if ($count === 0) {
            return [];
        }
        $offset = $count > $limit ? random_int(0, $count - $limit) : 0;
        $query = $extra + [
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
}
