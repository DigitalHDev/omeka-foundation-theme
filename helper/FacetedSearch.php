<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Faceted search / results page for the ceramics archive.
 *
 * Backs the unified results page (designs/search.html) reached from the header
 * search-bar, the slide-in search panel, and the hamburger item-type links. It
 * searches item TITLES with "contains" and narrows by four columns: item type,
 * decade, domain and event type.
 *
 * Domains (cidoc:P19i_was_made_for) and event types (dcterms:type) live on
 * Events, so for Documents they are applied through the data graph
 * (Document -> its Event -> the Event's domain/type). The traversal RULES are
 * kept identical to ItemRelations / SecondDegreeResources and MUST NOT be forked
 * (see the SecondDegreeResources policy in CLAUDE.md):
 *
 *   - Document -> Event : Document references the Event via dcterms:relation (13)
 *   - Event    -> Person: Event references the Person via a creator-role prop
 *                         (ItemRelations::ROLE_PROPS)
 *   - Event    -> Org   : Event references the Org via dcterms:relation (13)
 *
 * The four columns combine as: across columns = AND, within a column = OR. OR
 * groups are resolved as separate id-only (scalar) queries and combined with PHP
 * set operations, so only the visible page is ever hydrated.
 */
class FacetedSearch extends AbstractHelper
{
    const TPL_DOCUMENT = 15;
    const TPL_EVENT = 16;
    const TPL_PERSON = 17;
    const TPL_ORGANIZATION = 18;
    const TPL_PHOTOGRAPH = 20;

    const PROP_TITLE = 'dcterms:title';
    const PROP_DATE = 'dcterms:date';
    const PROP_TYPE = 'dcterms:type';
    const PROP_RELATION = 'dcterms:relation';
    const PROP_DOMAIN = 'cidoc:P19i_was_made_for';

    /** Creator-role properties connecting an Event to People (mirror ItemRelations::ROLE_PROPS). */
    const ROLE_PROPS = [501, 502, 518, 511, 514, 503, 500, 506, 504];

    /** dcterms:type value marking the Event subtype that carries a domain. */
    const EVENT_TYPE_WITH_DOMAIN = 'אירוע';

    /**
     * The selectable item types, in right-to-left display order (per design):
     * People, Events, Documents, Organizations.
     */
    const TYPES = [
        self::TPL_PERSON => 'אנשים',
        self::TPL_EVENT => 'אירועים',
        self::TPL_DOCUMENT => 'מסמכים',
        self::TPL_ORGANIZATION => 'ארגונים',
    ];

    /** @var \Omeka\Api\Manager */
    protected $api;

    /** @var int|null */
    protected $siteId;

    // Parsed request state.
    protected $q = '';
    protected $types = [];
    protected $decades = [];
    protected $domains = [];
    protected $etypes = [];
    protected $page = 1;
    protected $perPage = 24;

    /** @var array|null Memoized full result id list. */
    protected $resultIds = null;

    public function __invoke()
    {
        $view = $this->getView();
        $this->api = $view->api();
        $this->siteId = isset($view->site) ? $view->site->id() : null;
        $this->perPage = (int) $view->siteSetting('pagination_per_page', 24) ?: 24;
        $this->parseParams();
        return $this;
    }

    // ---- request parsing ---------------------------------------------------

    protected function parseParams(): void
    {
        $query = $this->getView()->params()->fromQuery();

        // Title term: prefer "q"; fall back to the core property-title scheme the
        // header used previously (property[0][text]).
        $this->q = trim((string) ($query['q'] ?? $this->legacyTitleTerm($query)));

        // Item types: prefer "types[]"; fall back to "resource_template_id".
        $rawTypes = $query['types'] ?? $query['resource_template_id'] ?? [];
        $this->types = $this->intList($rawTypes, array_keys(self::TYPES));

        $this->decades = array_values(array_filter(array_map('intval', (array) ($query['decades'] ?? []))));
        $this->domains = $this->stringList($query['domains'] ?? []);
        $this->etypes = $this->stringList($query['etypes'] ?? []);

        $this->page = max(1, (int) ($query['page'] ?? 1));
    }

    protected function legacyTitleTerm(array $query): string
    {
        if (!empty($query['property'][0]['text']) && (string) ($query['property'][0]['property'] ?? '') === '1') {
            return (string) $query['property'][0]['text'];
        }
        return '';
    }

    /** Whitelist a list of ints against allowed values; empty input => all allowed. */
    protected function intList($raw, array $allowed): array
    {
        $ids = array_map('intval', (array) $raw);
        $ids = array_values(array_intersect($ids, $allowed));
        return $ids ?: $allowed;
    }

    protected function stringList($raw): array
    {
        $out = [];
        foreach ((array) $raw as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    // ---- public accessors used by the template -----------------------------

    public function term(): string
    {
        return $this->q;
    }

    public function selectedTypes(): array
    {
        return $this->types;
    }

    public function selectedDecades(): array
    {
        return $this->decades;
    }

    public function selectedDomains(): array
    {
        return $this->domains;
    }

    public function selectedEventTypes(): array
    {
        return $this->etypes;
    }

    /** True when domain/event-type columns are active (Events and/or Documents selected). */
    public function domainColumnsEnabled(): bool
    {
        return in_array(self::TPL_EVENT, $this->types, true)
            || in_array(self::TPL_DOCUMENT, $this->types, true);
    }

    public function totalCount(): int
    {
        return count($this->computeResultIds());
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function totalPages(): int
    {
        return (int) ceil($this->totalCount() / $this->perPage) ?: 1;
    }

    /**
     * Hydrated resources for the current page, sorted by dcterms:date ascending.
     *
     * @return \Omeka\Api\Representation\ItemRepresentation[]
     */
    public function pageResults(): array
    {
        $ids = $this->computeResultIds();
        if (!$ids) {
            return [];
        }
        $query = [
            'id' => $ids,
            'sort_by' => 'dcterms:date',
            'sort_order' => 'asc',
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        return $this->api->search('items', $query)->getContent();
    }

    // ---- result id resolution (resolve-then-union) -------------------------

    protected function computeResultIds(): array
    {
        if ($this->resultIds !== null) {
            return $this->resultIds;
        }

        $hasDecade = !empty($this->decades);
        $eventConstraintActive = $this->domainColumnsEnabled()
            && (!empty($this->domains) || !empty($this->etypes));

        // E: events matching the selected domain(s) AND event type(s).
        $eventSet = $eventConstraintActive ? $this->eventsMatchingDomainType() : null;
        // Events whose own date falls in the selected decade(s) (for People/Org derivation).
        $eventsInDecade = $hasDecade ? $this->eventsInDecades($this->decades) : null;

        $all = [];
        foreach ($this->types as $tpl) {
            foreach ($this->idsForType($tpl, $eventSet, $eventsInDecade, $eventConstraintActive) as $id) {
                $all[$id] = true;
            }
        }

        $this->resultIds = array_keys($all);
        return $this->resultIds;
    }

    protected function idsForType(int $tpl, ?array $eventSet, ?array $eventsInDecade, bool $eventConstraintActive): array
    {
        switch ($tpl) {
            case self::TPL_EVENT:
                // Own title + own decade, then intersect with the domain/type set.
                $base = $this->ownBase($tpl);
                if ($eventConstraintActive) {
                    $base = array_values(array_intersect($base, $eventSet ?? []));
                }
                return $base;

            case self::TPL_DOCUMENT:
                // Own title + own decade, then narrow to docs related to events in E.
                $base = $this->ownBase($tpl);
                if ($eventConstraintActive) {
                    $related = $this->documentsRelatedToEvents($eventSet ?? []);
                    $base = array_values(array_intersect($base, $related));
                }
                return $base;

            case self::TPL_PERSON:
            case self::TPL_ORGANIZATION:
                // Title only; decade is derived from connected events. No domain/type.
                $base = $this->scalarIds($this->typeQuery($tpl));
                if ($eventsInDecade !== null) {
                    $connected = $this->resourcesConnectedToEvents($tpl, $eventsInDecade);
                    $base = array_values(array_intersect($base, $connected));
                }
                return $base;
        }
        return [];
    }

    /**
     * Items of a template matching the title term and (if any) the selected
     * decades on their OWN dcterms:date. Decades are OR'd via per-decade queries.
     */
    protected function ownBase(int $tpl): array
    {
        if (empty($this->decades)) {
            return $this->scalarIds($this->typeQuery($tpl));
        }
        $ids = [];
        foreach ($this->decades as $start) {
            $query = $this->typeQuery($tpl);
            $query['property'][] = ['property' => self::PROP_DATE, 'type' => 'yrgte', 'text' => $start];
            $query['property'][] = ['property' => self::PROP_DATE, 'type' => 'yrlte', 'text' => $start + 9];
            foreach ($this->scalarIds($query) as $id) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /** Base query for a template: site scope + title-contains. */
    protected function typeQuery(int $tpl): array
    {
        $query = ['resource_template_id' => $tpl];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        if ($this->q !== '') {
            $query['property'][] = ['property' => self::PROP_TITLE, 'type' => 'in', 'text' => $this->q];
        }
        return $query;
    }

    /**
     * Events (template 16) matching the selected domain(s) AND event type(s).
     * Each value is its own OR group resolved as a scalar query; groups are
     * combined with intersection.
     */
    protected function eventsMatchingDomainType(): array
    {
        $sets = [];
        if (!empty($this->domains)) {
            $sets[] = $this->eventsByPropertyValues(self::PROP_DOMAIN, $this->domains);
        }
        if (!empty($this->etypes)) {
            $sets[] = $this->eventsByPropertyValues(self::PROP_TYPE, $this->etypes);
        }
        if (!$sets) {
            return [];
        }
        $result = array_shift($sets);
        foreach ($sets as $set) {
            $result = array_values(array_intersect($result, $set));
        }
        return $result;
    }

    /** Union of Event ids whose $prop exactly equals any of $values. */
    protected function eventsByPropertyValues(string $prop, array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $query = ['resource_template_id' => self::TPL_EVENT];
            if ($this->siteId) {
                $query['site_id'] = $this->siteId;
            }
            $query['property'][] = ['property' => $prop, 'type' => 'eq', 'text' => $value];
            foreach ($this->scalarIds($query) as $id) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /** Union of Event ids whose own dcterms:date falls in any selected decade. */
    protected function eventsInDecades(array $decades): array
    {
        $ids = [];
        foreach ($decades as $start) {
            $query = ['resource_template_id' => self::TPL_EVENT];
            if ($this->siteId) {
                $query['site_id'] = $this->siteId;
            }
            $query['property'][] = ['property' => self::PROP_DATE, 'type' => 'yrgte', 'text' => $start];
            $query['property'][] = ['property' => self::PROP_DATE, 'type' => 'yrlte', 'text' => $start + 9];
            foreach ($this->scalarIds($query) as $id) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /** Documents (15) that reference any event in $eventIds via dcterms:relation. */
    protected function documentsRelatedToEvents(array $eventIds): array
    {
        if (!$eventIds) {
            return [];
        }
        $query = ['resource_template_id' => self::TPL_DOCUMENT];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        // All clauses OR'd: relation = e1 OR relation = e2 OR ...
        foreach ($eventIds as $eid) {
            $query['property'][] = ['property' => self::PROP_RELATION, 'type' => 'res', 'text' => $eid, 'joiner' => 'or'];
        }
        return $this->scalarIds($query);
    }

    /**
     * People (17) or Organizations (18) that the given events reference. Events
     * point at People through creator-role props and at Orgs through
     * dcterms:relation, so the events are read and their value-resources of the
     * requested template collected.
     */
    protected function resourcesConnectedToEvents(int $tpl, array $eventIds): array
    {
        if (!$eventIds) {
            return [];
        }
        $query = ['id' => $eventIds];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        $events = $this->api->search('items', $query)->getContent();

        $rolePropIds = array_flip(self::ROLE_PROPS);
        $ids = [];
        foreach ($events as $event) {
            foreach ($event->values() as $term => $propertyData) {
                $propertyId = $propertyData['property']->id();
                $isRelevant = $tpl === self::TPL_PERSON
                    ? isset($rolePropIds[$propertyId])
                    : ($term === self::PROP_RELATION);
                if (!$isRelevant) {
                    continue;
                }
                foreach ($propertyData['values'] as $value) {
                    $linked = $value->valueResource();
                    if ($linked
                        && $linked->resourceTemplate()
                        && $linked->resourceTemplate()->id() == $tpl
                    ) {
                        $ids[$linked->id()] = true;
                    }
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * Run an id-only (scalar) item search and return a flat list of ids.
     *
     * @return int[]
     */
    protected function scalarIds(array $query): array
    {
        $query['return_scalar'] = 'id';
        $content = $this->api->search('items', $query)->getContent();
        return array_values(array_map('intval', $content));
    }

    // ---- facet value lists (via the Reference module) ----------------------

    /** Item-type checkboxes: [['id' => int, 'label' => string], ...] in design order. */
    public function typeFacets(): array
    {
        $facets = [];
        foreach (self::TYPES as $id => $label) {
            $facets[] = ['id' => $id, 'label' => $label];
        }
        return $facets;
    }

    /**
     * Decade buckets present across Events, Documents and Photographs, newest
     * first: [['start' => 1990, 'label' => '1990-2000'], ...].
     */
    public function decadeFacets(): array
    {
        $years = $this->referenceValues(
            self::PROP_DATE,
            [self::TPL_EVENT, self::TPL_DOCUMENT, self::TPL_PHOTOGRAPH],
            true
        );
        $decades = [];
        foreach ($years as $year) {
            if (preg_match('/(\d{4})/', (string) $year, $m)) {
                $start = ((int) floor((int) $m[1] / 10)) * 10;
                $decades[$start] = true;
            }
        }
        $starts = array_keys($decades);
        rsort($starts);
        return array_map(function ($start) {
            return ['start' => $start, 'label' => $start . '-' . ($start + 10)];
        }, $starts);
    }

    /** Distinct domain values (custom vocab #9) present on Events. */
    public function domainFacets(): array
    {
        return $this->referenceValues(self::PROP_DOMAIN, [self::TPL_EVENT]);
    }

    /** Distinct event-type values present on Events. */
    public function eventTypeFacets(): array
    {
        return $this->referenceValues(self::PROP_TYPE, [self::TPL_EVENT]);
    }

    /**
     * Distinct values of a property across the given templates, via the Reference
     * module. Returns a flat, ordered list of value strings.
     *
     * @return string[]
     */
    protected function referenceValues(string $property, array $templateIds, bool $firstDigits = false): array
    {
        $view = $this->getView();
        if (!method_exists($view, 'references') && !$view->getHelperPluginManager()->has('references')) {
            return [];
        }
        $query = ['resource_template_id' => $templateIds];
        if ($this->siteId) {
            $query['site_id'] = $this->siteId;
        }
        try {
            // The view helper's __invoke() takes no args; the query goes to list().
            $result = $view->references()->list(
                [$property],
                $query,
                [
                    'resource_name' => 'items',
                    'output' => 'associative',
                    'first_digits' => $firstDigits,
                    'sort_by' => 'alphabetic',
                ]
            );
        } catch (\Throwable $e) {
            return [];
        }
        // Be tolerant of the wrapper shape: result may be keyed by the field and
        // may nest the value map under "o:references".
        $data = $result[$property] ?? $result;
        $references = (is_array($data) && isset($data['o:references'])) ? $data['o:references'] : $data;
        if (!is_array($references)) {
            return [];
        }
        $values = array_keys($references);
        // Drop empty values.
        return array_values(array_filter($values, function ($v) {
            return trim((string) $v) !== '';
        }));
    }
}
