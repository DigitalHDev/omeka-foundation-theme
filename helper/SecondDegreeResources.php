<?php
// themes/YourTheme/Helper/SecondDegreeResources.php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

class SecondDegreeResources extends AbstractHelper
{
    /**
     * Display resources that are linked from resources that link to the given resource
     * Can operate in two modes:
     * - Direct: Find resources (first degree) that reference current resource, then find resources (second degree) referenced BY those first degree resources
     * - Reverse: Find resources (first degree) that reference current resource, then find resources (second degree) that reference those first degree resources
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
        
        // Property IDs for filtering
        $firstDegreePropertyIds = $options['firstDegreePropertyIds'] ?? [];
        $secondDegreePropertyIds = $options['secondDegreePropertyIds'] ?? [];
        
        // Direction mode - 'direct' or 'reverse'
        $direction = $options['direction'] ?? 'direct';
        
        // Debug info
        echo "<!-- DEBUG SecondDegreeResources: direction=$direction, firstDegreeTemplate=$firstDegreeResourceTemplate, secondDegreeTemplate=$secondDegreeResourceTemplate -->";
        echo "<!-- DEBUG FirstDegreePropertyIds: " . implode(',', $firstDegreePropertyIds) . " -->";
        echo "<!-- DEBUG SecondDegreePropertyIds: " . implode(',', $secondDegreePropertyIds) . " -->";
        
        // Get first degree resources that link to the current resource
        $firstDegreeResources = $this->getFirstDegreeResources(
            $resource, 
            $firstDegreeResourceType, 
            $firstDegreeResourceTemplate,
            $siteId,
            $firstDegreePropertyIds
        );
        
        if (empty($firstDegreeResources)) {
            // No first degree resources found
            return null;
        }
        
        // Debug first degree resources
        echo "<!-- DEBUG: Found " . count($firstDegreeResources) . " first degree resources -->";
        if (!empty($firstDegreeResources)) {
            foreach ($firstDegreeResources as $index => $res) {
                echo "<!-- DEBUG: First degree #$index: ID=" . $res->id() . ", Title='" . 
                     htmlspecialchars($res->displayTitle()) . "' -->";
            }
        }
        
        // Get second degree resources based on direction
        $secondDegreeData = [];
        if ($direction === 'direct') {
            echo "<!-- DEBUG: Using DIRECT mode for second degree resources -->";
            // Direct mode: Find resources referenced BY the first degree resources
            $secondDegreeData = $this->getSecondDegreeResourcesFromLinks(
                $firstDegreeResources,
                $secondDegreeResourceType,
                $secondDegreeResourceTemplate,
                $page,
                $perPage,
                $siteId,
                $secondDegreePropertyIds
            );
        } else {
            echo "<!-- DEBUG: Using REVERSE mode for second degree resources -->";
            // Reverse mode: Find resources that reference the first degree resources
            $secondDegreeData = $this->getSecondDegreeResourcesReferringToLinks(
                $firstDegreeResources,
                $secondDegreeResourceType,
                $secondDegreeResourceTemplate,
                $page,
                $perPage,
                $siteId,
                $secondDegreePropertyIds
            );
        }
        
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
    protected function getFirstDegreeResources($resource, $resourceType, $templateId = null, $siteId = null, $propertyIds = [])
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Get subject value properties filtered by provided property IDs
        if (!empty($propertyIds)) {
            // Use only specified property IDs
            $subjectValueProperties = $this->getSpecificProperties($propertyIds);
        } else {
            // Fallback to all properties if none specified
            $subjectValueProperties = $this->getSubjectValueProperties($resource, $resourceType, $siteId);
        }
        
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
     * Get resources that are linked FROM the first degree resources (Direct mode)
     * This is the original implementation renamed for clarity
     */
    protected function getSecondDegreeResourcesFromLinks($firstDegreeResources, $resourceType, $templateId = null, 
                                                      $page = null, $perPage = null, $siteId = null, $propertyIds = [])
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Get property terms if property IDs are provided
        $propertyTerms = [];
        if (!empty($propertyIds)) {
            foreach ($propertyIds as $propertyId) {
                try {
                    $property = $api->read('properties', ['id' => $propertyId])->getContent();
                    $propertyTerms[] = $property->term();
                } catch (\Exception $e) {
                    // Property not found, continue
                }
            }
        }
        
        // Store all unique second degree resources
        $allSecondDegreeResources = [];
        $totalCount = 0;
        $seen = []; // Track IDs we've already processed
        
        // For each first degree resource, find resources it links to
        foreach ($firstDegreeResources as $firstDegreeResource) {
            // Get all values where this resource links to other resources
            $values = $firstDegreeResource->values();
            
            foreach ($values as $term => $propertyValues) {
                // Skip if we're filtering by property terms and this term isn't in the list
                if (!empty($propertyTerms) && !in_array($term, $propertyTerms)) {
                    continue;
                }
                
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
     * Get resources that link TO the first degree resources (Reverse mode)
     * This is the new method for reverse second degree relationships
     */
    protected function getSecondDegreeResourcesReferringToLinks($firstDegreeResources, $resourceType, $templateId = null, 
                                                             $page = null, $perPage = null, $siteId = null, $propertyIds = [])
    {
        $view = $this->getView();
        $api = $view->api();
        
        echo "<!-- DEBUG Reverse: Starting with " . count($firstDegreeResources) . " first degree resources -->";
        
        // Store all unique second degree resources
        $allSecondDegreeResources = [];
        $totalCount = 0;
        $seen = []; // Track IDs we've already processed
        
        // For each first degree resource, find resources that link to it
        foreach ($firstDegreeResources as $firstDegreeResource) {
            $firstDegreeId = $firstDegreeResource->id();
            
            echo "<!-- DEBUG Reverse: Processing first degree resource ID=$firstDegreeId, Title='" . 
                 htmlspecialchars($firstDegreeResource->displayTitle()) . "' -->";
            
            // For each specified property, search for resources that link to this first degree resource
            foreach ($propertyIds as $propertyId) {
                echo "<!-- DEBUG Reverse: Searching with property ID=$propertyId -->";
                
                // Query for resources that link to the first degree resource using this property
                $query = [
                    'property' => [
                        [
                            'property' => $propertyId,
                            'type' => 'res',
                            'text' => $firstDegreeId
                        ]
                    ]
                ];
                
                // Add template filter if specified
                if ($templateId) {
                    $query['resource_template_id'] = $templateId;
                    echo "<!-- DEBUG Reverse: Filtering by template ID=$templateId -->";
                }
                
                // Add site filter if specified
                if ($siteId) {
                    $query['site_id'] = $siteId;
                    echo "<!-- DEBUG Reverse: Filtering by site ID=$siteId -->";
                }
                
                echo "<!-- DEBUG Reverse: Full query: " . json_encode($query) . " -->";
                
                // Execute API query
                try {
                    $response = $api->search($resourceType, $query);
                    $resultCount = $response->getTotalResults();
                    echo "<!-- DEBUG Reverse: Query returned $resultCount results -->";
                    
                    foreach ($response->getContent() as $linkedResource) {
                        $resourceId = $linkedResource->id();
                        
                        echo "<!-- DEBUG Reverse: Found linked resource ID=$resourceId, Title='" . 
                             htmlspecialchars($linkedResource->displayTitle()) . "' -->";
                        
                        // Skip if we've already seen this resource
                        if (isset($seen[$resourceId])) {
                            echo "<!-- DEBUG Reverse: Skipping duplicate resource ID=$resourceId -->";
                            continue;
                        }
                        
                        $seen[$resourceId] = true;
                        $totalCount++;
                        
                        // Get the property for context
                        $property = null;
                        try {
                            $property = $api->read('properties', ['id' => $propertyId])->getContent();
                        } catch (\Exception $e) {
                            echo "<!-- DEBUG Reverse: Error reading property ID=$propertyId: " . 
                                 htmlspecialchars($e->getMessage()) . " -->";
                            // Property not found, continue anyway
                        }
                        
                        $allSecondDegreeResources[] = [
                            'resource' => $linkedResource,
                            'property' => $property,
                            'connectingResource' => $firstDegreeResource // Store the connecting resource for context
                        ];
                    }
                } catch (\Exception $e) {
                    echo "<!-- DEBUG Reverse: Error in search: " . htmlspecialchars($e->getMessage()) . " -->";
                    // Error in search, continue with next property
                }
                
                // Try to get the property label for better debugging
                try {
                    $propInfo = $api->read('properties', ['id' => $propertyId])->getContent();
                    echo "<!-- DEBUG Reverse: Property ID=$propertyId is '" . $propInfo->label() . "' (" . $propInfo->term() . ") -->";
                } catch (\Exception $e) {
                    echo "<!-- DEBUG Reverse: Couldn't get property label for ID=$propertyId -->";
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
     * Get specific properties by their IDs
     */
    protected function getSpecificProperties($propertyIds)
    {
        $view = $this->getView();
        $api = $view->api();
        
        $properties = [];
        
        foreach ($propertyIds as $propertyId) {
            try {
                $response = $api->read('properties', ['id' => $propertyId]);
                $property = $response->getContent();
                $properties[] = [
                    'property' => $property
                ];
            } catch (\Exception $e) {
                // Property not found, continue
            }
        }
        
        return $properties;
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