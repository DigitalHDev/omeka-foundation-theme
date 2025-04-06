<?php
// themes/YourTheme/Helper/SecondDegreeResources.php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

class SecondDegreeResources extends AbstractHelper
{
    /**
     * Display resources that are linked from resources that link to the given resource
     *
     * For example:
     * - Given an Organization (current resource)
     * - Find Events (first degree) that reference this Organization
     * - Find People (second degree) that are referenced by those Events
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource The resource to find second-degree connections for
     * @param array $options Options for filtering and display
     * @return string HTML output
     */
    public function __invoke($resource, array $options = [])
    {
        $view = $this->getView();
        
        // Set default options
        $page = $options['page'] ?? null;
        $perPage = $options['perPage'] ?? null;
        $siteId = $options['siteId'] ?? (isset($view->site) ? $view->site->id() : null);
        
        // Template filters
        $firstDegreeResourceTemplate = $options['firstDegreeTemplate'] ?? null;
        $secondDegreeResourceTemplate = $options['secondDegreeTemplate'] ?? null;
        
        // Resource types
        $firstDegreeResourceType = $options['firstDegreeResourceType'] ?? 'items';
        $secondDegreeResourceType = $options['secondDegreeResourceType'] ?? 'items';
        
        // Get first degree resources that link to the current resource
        $firstDegreeResources = $this->getFirstDegreeResources(
            $resource, 
            $firstDegreeResourceType, 
            $firstDegreeResourceTemplate,
            $siteId
        );
        
        if (empty($firstDegreeResources)) {
            // No first degree resources found
            return null;
        }
        
        // Get the property links FROM the first degree resources (e.g., from Events TO People)
        $secondDegreeData = $this->getSecondDegreeResourcesFromLinks(
            $firstDegreeResources,
            $secondDegreeResourceType,
            $secondDegreeResourceTemplate,
            $page,
            $perPage,
            $siteId
        );
        
        if (empty($secondDegreeData['resources'])) {
            // No second degree resources found
            return null;
        }
        
        // Generate HTML directly for the linked resources
        $html = '<div class="subject-values">';
        $html .= '<div class="values">';
        
        foreach ($secondDegreeData['resources'] as $resourceData) {
            $linkedResource = $resourceData['resource'];
            $connectingResource = $resourceData['connectingResource'] ?? null;
            
            $html .= '<div class="value">';
            
            // Add the resource link
            if (method_exists($linkedResource, 'linkPretty')) {
                // Use built-in method if available
                $html .= $linkedResource->linkPretty();
            } else {
                // Fallback to a simple link
                $html .= '<a href="' . $view->escapeHtml($linkedResource->url()) . '">' 
                       . $view->escapeHtml($linkedResource->displayTitle()) . '</a>';
            }
            
            // Optionally show which first-degree resource connects them
            if ($connectingResource && isset($options['showConnectingResource']) && $options['showConnectingResource']) {
                $html .= ' <span class="connecting-resource">(via ';
                $html .= '<a href="' . $view->escapeHtml($connectingResource->url()) . '">' 
                       . $view->escapeHtml($connectingResource->displayTitle()) . '</a>';
                $html .= ')</span>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>'; // close values
        
        // Add pagination if needed
        if ($page !== null && $perPage !== null && $secondDegreeData['totalCount'] > $perPage) {
            // Try to use the same pagination helper as Omeka
            if (method_exists($view, 'pagination')) {
                $paginationHtml = $view->pagination(null, $secondDegreeData['totalCount'], $page, $perPage);
                if ($paginationHtml) {
                    $html .= '<div class="pagination">' . $paginationHtml . '</div>';
                }
            }
        }
        
        $html .= '</div>'; // close subject-values
        
        return $html;
    }
    
    /**
     * Get resources of a specific type and template that link to the current resource
     * These are the first-degree connections (e.g., Events that reference an Organization)
     */
    protected function getFirstDegreeResources($resource, $resourceType, $templateId = null, $siteId = null)
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Get subject values for the specific resource type
        $subjectValueProperties = $this->getSubjectValueProperties($resource, $resourceType, $siteId);
        
        if (empty($subjectValueProperties)) {
            return [];
        }
        
        // Get all subject values matching the resource type
        $allSubjectValues = [];
        
        foreach ($subjectValueProperties as $property) {
            $propertyId = $property['property']->id();
            
            // Determine query parameters
            $query = [
                'property' => [
                    [
                        'property' => $propertyId,
                        'type' => 'res',
                        'text' => $resource->id()
                    ]
                ]
            ];
            
            // Add site filter if specified
            if ($siteId) {
                $query['site_id'] = $siteId;
            }
            
            // Add template filter if specified
            if ($templateId) {
                $query['resource_template_id'] = $templateId;
            }
            
            // Execute API query
            $response = $api->search($resourceType, $query);
            
            foreach ($response->getContent() as $linkedResource) {
                $allSubjectValues[] = [
                    'resource' => $linkedResource,
                    'property' => $property['property']
                ];
            }
        }
        
        // Extract just the resources
        $resources = [];
        foreach ($allSubjectValues as $subjectValue) {
            $resources[] = $subjectValue['resource'];
        }
        
        return $resources;
    }
    
    /**
     * Get resources that are linked FROM the first degree resources
     * For example, find People referenced by Events
     * This is different from the original approach which looked for resources linking TO the first degree
     */
    protected function getSecondDegreeResourcesFromLinks($firstDegreeResources, $resourceType, $templateId = null, $page = null, $perPage = null, $siteId = null)
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Store all unique second degree resources
        $allSecondDegreeResources = [];
        $totalCount = 0;
        $seen = []; // Track IDs we've already processed
        
        // For each first degree resource, find resources it links to
        foreach ($firstDegreeResources as $firstDegreeResource) {
            // Get all values where this resource links to other resources
            $values = $firstDegreeResource->values();
            
            foreach ($values as $term => $propertyValues) {
                foreach ($propertyValues['values'] as $value) {
                    // Only process resource links
                    if ($value->type() !== 'resource') {
                        continue;
                    }
                    
                    $linkedResource = $value->valueResource();
                    
                    // Skip if resource doesn't exist or isn't of the correct type
                    if (!$linkedResource || $linkedResource->resourceName() !== $resourceType) {
                        continue;
                    }
                    
                    // Skip if template filter is specified and doesn't match
                    if ($templateId && $linkedResource->resourceTemplate() && 
                        $linkedResource->resourceTemplate()->id() != $templateId) {
                        continue;
                    }
                    
                    // Skip if site filter is specified and resource not in site
                    if ($siteId) {
                        // Check if resource is in the site
                        // This is a simplified check - you might need to adjust based on how your
                        // site determines resource visibility
                        $inSite = true; // Placeholder - implement real check if needed
                        if (!$inSite) {
                            continue;
                        }
                    }
                    
                    $resourceId = $linkedResource->id();
                    
                    // Skip if we've already seen this resource
                    if (isset($seen[$resourceId])) {
                        continue;
                    }
                    
                    $seen[$resourceId] = true;
                    $totalCount++;
                    
                    // Find the property representation for the current term
                    $property = null;
                    try {
                        $propertyResponse = $api->read('properties', ['term' => $term]);
                        $property = $propertyResponse->getContent();
                    } catch (\Exception $e) {
                        // Property not found, continue anyway
                    }
                    
                    $allSecondDegreeResources[] = [
                        'resource' => $linkedResource,
                        'property' => $property,
                        'connectingResource' => $firstDegreeResource // Store the connecting resource for context
                    ];
                }
            }
        }
        
        // Apply pagination if needed
        if ($page !== null && $perPage !== null) {
            $offset = ($page - 1) * $perPage;
            $allSecondDegreeResources = array_slice($allSecondDegreeResources, $offset, $perPage);
        }
        
        return [
            'resources' => $allSecondDegreeResources,
            'totalCount' => $totalCount
        ];
    }
    
    /**
     * Get the subject value properties for a resource
     * This is a replacement for the adapter->getSubjectValueProperties() method
     * that works with both standard Omeka and with Advanced Search module
     */
    protected function getSubjectValueProperties($resource, $resourceType, $siteId = null)
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Get all properties
        $response = $api->search('properties');
        $properties = $response->getContent();
        
        $subjectValueProperties = [];
        
        foreach ($properties as $property) {
            $subjectValueProperties[] = [
                'property' => $property
            ];
        }
        
        return $subjectValueProperties;
    }
}