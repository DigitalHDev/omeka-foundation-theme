<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The stand-in image for a resource that has no thumbnail of its own, chosen by
 * resource template.
 *
 * This helper owns the template -> placeholder-asset mapping and nothing else.
 * Resolving a resource's REAL thumbnail stays where it already lives -
 * ItemRelations::cardData() for result cards (including the Event's borrowed
 * Document image) and thumbnailDisplayUrl() at the hero call sites - so this
 * forks no traversal rule.
 *
 * Returns null for a template with no placeholder, so callers can keep writing
 * `$thumb ?: $this->ResourcePlaceholder($r)` and still get null when there is
 * nothing at all to show.
 */
class ResourcePlaceholder extends AbstractHelper
{
    /** Resource template id => asset path under asset/. */
    const PLACEHOLDERS = [
        15 => 'img/place-holder_51.png',     // Document
        16 => 'img/event-placeholder.png',   // Event
        17 => 'img/person-placeholder.jpg',  // Person
        18 => 'img/org-placeholder.jpg',     // Organization
        20 => 'img/place-holder_51.png',     // Photograph
    ];

    /**
     * @return string|null Asset URL, or null when the template has no placeholder.
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        $template = $resource->resourceTemplate();
        $id = $template ? $template->id() : null;
        if ($id === null || !isset(self::PLACEHOLDERS[$id])) {
            return null;
        }
        return $this->getView()->assetUrl(self::PLACEHOLDERS[$id]);
    }
}
