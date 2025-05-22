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
        
        // Get second degree resources based on direction
        $secondDegreeData = [];
        if ($direction === 'direct') {
            // Direct mode: Find resources referenced BY the first degree resources
            $secondDegreeData = $this->getSecondDegreeResourcesFromLinks(
                $firstDegreeResources,
                $secondDegreeResourceType,
                $secondDegreeResourceTemplate,
                $siteId,
                $secondDegreePropertyIds
            );
        } else {
            // Reverse mode: Find resources that reference the first degree resources
            $secondDegreeData = $this->getSecondDegreeResourcesReferringToLinks(
                $firstDegreeResources,
                $secondDegreeResourceType,
                $secondDegreeResourceTemplate,
                $siteId,
                $secondDegreePropertyIds
            );
        }
        
        if (empty($secondDegreeData['resources'])) {
            // No second degree resources found
            return null;
        }
        
        // Check if we should use nested list format for People (template ID 17)
        $useNestedList = ($secondDegreeResourceTemplate == 17);
        
        if ($useNestedList) {
            // Generate nested list for People, grouped by property
            $html = '<div class="subject-values">';
            $html .= '<div class="values nested-list">';
            
            // Group resources by property
            $resourcesByProperty = [];
            
            foreach ($secondDegreeData['resources'] as $resourceData) {
                $linkedResource = $resourceData['resource'];
                $property = $resourceData['property'] ?? null;
                
                if (!$property) {
                    // Skip resources without properties
                    continue;
                }
                
                $resourceId = $linkedResource->id();
                $propertyId = $property->id();
                $propertyLabel = $view->translate($property->label());
                
                // Initialize property group if not exists
                if (!isset($resourcesByProperty[$propertyLabel])) {
                    $resourcesByProperty[$propertyLabel] = [
                        'property' => $property,
                        'resources' => []
                    ];
                }
                
                // Add resource if not already present
                $resourcesByProperty[$propertyLabel]['resources'][$resourceId] = $linkedResource;
            }
            
            // Sort properties alphabetically
            ksort($resourcesByProperty);
            
            // Build the nested HTML structure
            foreach ($resourcesByProperty as $propertyLabel => $data) {
                $html .= '<div class="property-group">';
                $html .= '<h4 class="property-name">' . $view->escapeHtml($propertyLabel) . '</h4>';
                $html .= '<ul class="people-list">';
                
                // Sort people by display title
                $resources = $data['resources'];
                usort($resources, function($a, $b) {
                    return strcmp($a->displayTitle(), $b->displayTitle());
                });
                
                // Add each person as a list item
                foreach ($resources as $linkedResource) {
                    $html .= '<li>';
                    $html .= '<a href="' . $view->escapeHtml($linkedResource->url()) . '">' 
                           . $view->escapeHtml($linkedResource->displayTitle()) . '</a>';
                    $html .= '</li>';
                }
                
                $html .= '</ul>';
                $html .= '</div>';
            }
            
            $html .= '</div>'; // close values
        } else {
            // Original div-based display for other templates
            $html = '<div class="subject-values">';
            $html .= '<div class="values">';
            
            $uniqueResources = []; // Track unique resources
            $resourcesToDisplay = []; // Store resources for sorting
            
            foreach ($secondDegreeData['resources'] as $resourceData) {
                $linkedResource = $resourceData['resource'];
                $connectingResource = $resourceData['connectingResource'] ?? null;
                
                // Skip if we've already seen this resource
                $resourceId = $linkedResource->id();
                if (isset($uniqueResources[$resourceId])) {
                    continue;
                }
                
                // Mark this resource as seen
                $uniqueResources[$resourceId] = true;
                
                // Store for potential sorting
                $resourcesToDisplay[] = [
                    'resource' => $linkedResource,
                    'connectingResource' => $connectingResource
                ];
            }
            
            // Sort by date if template ID is 15 (document) or 20 (photograph)
            if ($secondDegreeResourceTemplate == 15 || $secondDegreeResourceTemplate == 20) {
                $resourcesToDisplay = $this->sortResourcesByDate($resourcesToDisplay);
            }
            
            // Generate HTML for each resource
            foreach ($resourcesToDisplay as $resourceData) {
                $linkedResource = $resourceData['resource'];
                $connectingResource = $resourceData['connectingResource'];
                
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
                
                //// Optionally show which first-degree resource connects them
                //if ($connectingResource && isset($options['showConnectingResource']) && $options['showConnectingResource']) {
                //    $html .= ' <span class="connecting-resource">(via ';
                //    $html .= '<a href="' . $view->escapeHtml($connectingResource->url()) . '">' 
                //           . $view->escapeHtml($connectingResource->displayTitle()) . '</a>';
                //    $html .= ')</span>';
                //}
                
                $html .= '</div>';
            }
            
            $html .= '</div>'; // close values
        }
        
        $html .= '</div>'; // close subject-values
        
        return $html;
    }
    
    /**
     * Sort resources by date (ascending)
     * Attempts to find date values in common date properties
     *
     * @param array $resourcesToDisplay Array of resource data arrays
     * @return array Sorted array of resource data
     */
    protected function sortResourcesByDate($resourcesToDisplay)
    {
        // Common date property terms to check (in order of preference)
        $datePropertyTerms = [
            'dcterms:date',
            'dcterms:created',
            'dcterms:issued',
            'dcterms:modified',
            'bibo:date'
        ];
        
        // Add date information to each resource for sorting
        foreach ($resourcesToDisplay as &$resourceData) {
            $resource = $resourceData['resource'];
            $dateValue = null;
            $dateTimestamp = null;
            
            // Try to find a date value in the resource
            foreach ($datePropertyTerms as $term) {
                $values = $resource->value($term, ['all' => true]);
                if ($values) {
                    foreach ($values as $value) {
                        $dateString = $value->value();
                        if ($dateString) {
                            // Try to parse the date
                            $timestamp = $this->parseDateToTimestamp($dateString);
                            if ($timestamp !== null) {
                                $dateValue = $dateString;
                                $dateTimestamp = $timestamp;
                                break 2; // Break out of both loops
                            }
                        }
                    }
                }
            }
            
            // Store date information for sorting
            $resourceData['dateValue'] = $dateValue;
            $resourceData['dateTimestamp'] = $dateTimestamp;
        }
        
        // Sort by date timestamp (ascending), with resources without dates at the end
        usort($resourcesToDisplay, function($a, $b) {
            $aTimestamp = $a['dateTimestamp'];
            $bTimestamp = $b['dateTimestamp'];
            
            // If both have dates, sort by timestamp
            if ($aTimestamp !== null && $bTimestamp !== null) {
                return $aTimestamp <=> $bTimestamp;
            }
            
            // Resources with dates come before resources without dates
            if ($aTimestamp !== null && $bTimestamp === null) {
                return -1;
            }
            if ($aTimestamp === null && $bTimestamp !== null) {
                return 1;
            }
            
            // If neither has a date, sort by title
            return strcmp($a['resource']->displayTitle(), $b['resource']->displayTitle());
        });
        
        return $resourcesToDisplay;
    }
    
    /**
     * Parse a date string to a timestamp for sorting
     * Handles various date formats commonly found in Omeka
     *
     * @param string $dateString The date string to parse
     * @return int|null Unix timestamp or null if parsing fails
     */
    protected function parseDateToTimestamp($dateString)
    {
        if (empty($dateString)) {
            return null;
        }
        
        // Clean up the date string
        $dateString = trim($dateString);
        
        // Try various date formats
        $formats = [
            'Y-m-d',           // 2023-12-31
            'Y-m-d H:i:s',     // 2023-12-31 23:59:59
            'Y/m/d',           // 2023/12/31
            'd/m/Y',           // 31/12/2023
            'm/d/Y',           // 12/31/2023
            'Y',               // 2023 (year only)
            'Y-m',             // 2023-12 (year-month)
            'd-m-Y',           // 31-12-2023
            'F j, Y',          // December 31, 2023
            'j F Y',           // 31 December 2023
            'M j, Y',          // Dec 31, 2023
            'j M Y',           // 31 Dec 2023
        ];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->getTimestamp();
            }
        }
        
        // Try strtotime as a fallback
        $timestamp = strtotime($dateString);
        if ($timestamp !== false) {
            return $timestamp;
        }
        
        // If it's just a year (4 digits), create a date for January 1st of that year
        if (preg_match('/^\d{4}$/', $dateString)) {
            $year = (int)$dateString;
            if ($year > 1000 && $year < 3000) { // Reasonable year range
                return mktime(0, 0, 0, 1, 1, $year);
            }
        }
        
        return null;
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
                                                      $siteId = null, $propertyIds = [])
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Get property terms if property IDs are provided
        $propertyTerms = [];
        $propertyMap = []; // Map property terms to property objects for later use
        if (!empty($propertyIds)) {
            foreach ($propertyIds as $propertyId) {
                try {
                    $property = $api->read('properties', ['id' => $propertyId])->getContent();
                    $term = $property->term();
                    $propertyTerms[] = $term;
                    $propertyMap[$term] = $property; // Store property object by term
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
                
                // Get the property object for this term
                $property = $propertyMap[$term] ?? null;
                if (!$property && !empty($propertyTerms)) {
                    // Try to load property if not in map
                    try {
                        $propertyResponse = $api->read('properties', ['term' => $term]);
                        $property = $propertyResponse->getContent();
                    } catch (\Exception $e) {
                        // Property not found, continue anyway
                    }
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
                    $propertyId = $property ? $property->id() : 'null';
                    $uniqueKey = $resourceId . '-' . $propertyId;
                    
                    // Skip if we've already seen this resource-property combination
                    if (isset($seen[$uniqueKey])) {
                        continue;
                    }
                    
                    $seen[$uniqueKey] = true;
                    $totalCount++;
                    
                    $allSecondDegreeResources[] = [
                        'resource' => $linkedResource,
                        'property' => $property,
                        'connectingResource' => $firstDegreeResource // Store the connecting resource for context
                    ];
                }
            }
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
                                                             $siteId = null, $propertyIds = [])
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Store all unique second degree resources
        $allSecondDegreeResources = [];
        $totalCount = 0;
        $seen = []; // Track resource-property combinations we've already processed
        
        // Create a map of property IDs to property objects for quicker lookup
        $propertyMap = [];
        foreach ($propertyIds as $propertyId) {
            try {
                $property = $api->read('properties', ['id' => $propertyId])->getContent();
                $propertyMap[$propertyId] = $property;
            } catch (\Exception $e) {
                // Property not found, continue
            }
        }
        
        // For each first degree resource, find resources that link to it
        foreach ($firstDegreeResources as $firstDegreeResource) {
            $firstDegreeId = $firstDegreeResource->id();
            
            // For each specified property, search for resources that link to this first degree resource
            foreach ($propertyIds as $propertyId) {
                // Get the property object for this ID
                $property = $propertyMap[$propertyId] ?? null;
                
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
                }
                
                // Add site filter if specified
                if ($siteId) {
                    $query['site_id'] = $siteId;
                }
                
                // Execute API query
                try {
                    $response = $api->search($resourceType, $query);
                    
                    foreach ($response->getContent() as $linkedResource) {
                        $resourceId = $linkedResource->id();
                        $propertyId = $property ? $property->id() : 'null';
                        $uniqueKey = $resourceId . '-' . $propertyId;
                        
                        // Skip if we've already seen this resource-property combination
                        if (isset($seen[$uniqueKey])) {
                            continue;
                        }
                        
                        $seen[$uniqueKey] = true;
                        $totalCount++;
                        
                        $allSecondDegreeResources[] = [
                            'resource' => $linkedResource,
                            'property' => $property,
                            'connectingResource' => $firstDegreeResource // Store the connecting resource for context
                        ];
                    }
                } catch (\Exception $e) {
                    // Error in search, continue with next property
                }
            }
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