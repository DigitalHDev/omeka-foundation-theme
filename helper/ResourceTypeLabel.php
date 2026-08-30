<?php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The section label a resource is shown under, chosen by resource template.
 *
 * This is the string printed as the .hero-label on an item show page, and the
 * same string is reused by the .item-parent-bar back-link so the link names the
 * section it leads to ("חזרה אל: אירועים, שבילי יער"). This helper owns the
 * template -> label mapping and nothing else; it is the single source of truth,
 * so neither the hero nor a back-link may hard-code a label of its own.
 *
 * Not to be confused with FacetedSearch::TYPES, which is the search facet's own
 * checkbox list: it covers only the four selectable types and folds
 * Photographs (20) into Documents (15). Deliberately kept separate.
 *
 * Returns null for a template with no label, so a caller can fall back to
 * printing the bare title.
 */
class ResourceTypeLabel extends AbstractHelper
{
    /** Resource template id => section label. */
    const LABELS = [
        15 => 'מדיה',     // Document
        16 => 'אירועים',  // Event
        17 => 'אנשים',    // Person
        18 => 'ארגונים',  // Organization
        20 => 'צילומים',  // Photograph
    ];

    /**
     * @return string|null The label, or null when the template has none.
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        $template = $resource->resourceTemplate();
        $id = $template ? $template->id() : null;
        if ($id === null || !isset(self::LABELS[$id])) {
            return null;
        }
        return self::LABELS[$id];
    }
}
