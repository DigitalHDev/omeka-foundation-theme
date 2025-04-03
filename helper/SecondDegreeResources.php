<?php
// themes/YourTheme/Helper/SecondDegreeResources.php
namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

class SecondDegreeResources extends AbstractHelper
{
    /**
     * Display resources that link to resources that link to the given resource
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource The resource to find second-degree connections for
     * @param array $options Options for filtering and display
     * @return string HTML output
     */
    public function __invoke($resource, array $options = [])
    {
        $view = $this->getView();
        
        // Set default options
        $viewName = $options['viewName'] ?? 'common/linked-resources'; // Use the same view
        $page = $options['page'] ?? null;
        $perPage = $options['perPage'] ?? null;
        $siteId = $options['siteId'] ?? (isset($view->site) ? $view->site->id() : null);
        
        // Template filters
        $firstDegreeResourceTemplate = $options['firstDegreeTemplate'] ?? null;
        $secondDegreeResourceTemplate = $options['secondDegreeTemplate'] ?? null;
        
        // Resource types
        $firstDegreeResourceType = $options['firstDegreeResourceType'] ?? 'items';
        $secondDegreeResourceType = $options['secondDegreeResourceType'] ?? 'items';
        
        // Get first degree resources
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
        
        // Get second degree resources
        $secondDegreeData = $this->getSecondDegreeResources(
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
        
        // Convert to format expected by the linked-resources view
        $subjectValues = [];
        foreach ($secondDegreeData['resources'] as $resourceData) {
            $subjectValues[] = [
                'resource' => $resourceData['resource'],
                // The original expects a 'property' key, but it's only used for the label
                // We'll just provide a dummy property or you could pass a real one if needed
                'property' => null
            ];
        }
        
        // Create data structure expected by the linked-resources view
        $viewData = [
            'objectResource' => $resource,
            'subjectValues' => $subjectValues,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $secondDegreeData['totalCount'],
            'resourceProperty' => $secondDegreeResourceType . ':0', // Format: type:propertyId
            'propertyId' => 0, // No specific property filter
            'resourceType' => $secondDegreeResourceType,
            'resourcePropertiesAll' => [
                'items' => [],
                'item_sets' => [],
                'media' => []
            ]
        ];
        
        // Render the template
        return $view->partial($viewName, $viewData);
    }
    
    /**
     * Get resources of a specific type and template that link to the current resource
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
     * Get resources of a specific type and template that link to the provided first degree resources
     */
    protected function getSecondDegreeResources($firstDegreeResources, $resourceType, $templateId = null, $page = null, $perPage = null, $siteId = null)
    {
        $view = $this->getView();
        $api = $view->api();
        
        // Store all unique second degree resources
        $allSecondDegreeResources = [];
        $totalCount = 0;
        $seen = []; // Track IDs we've already processed
        
        // For each first degree resource, find resources that link to it
        foreach ($firstDegreeResources as $firstDegreeResource) {
            // Get the property IDs for links to this resource
            $subjectValueProperties = $this->getSubjectValueProperties($firstDegreeResource, $resourceType, $siteId);
            
            if (empty($subjectValueProperties)) {
                continue;
            }
            
            // For each property that can link to this resource
            foreach ($subjectValueProperties as $property) {
                $propertyId = $property['property']->id();
                
                // Determine query parameters
                $query = [
                    'property' => [
                        [
                            'property' => $propertyId,
                            'type' => 'res',
                            'text' => $firstDegreeResource->id()
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
                
                // Get the resources
                $response = $api->search($resourceType, $query);
                
                // Process results
                foreach ($response->getContent() as $resource) {
                    $resourceId = $resource->id();
                    
                    // Skip if we've already seen this resource
                    if (isset($seen[$resourceId])) {
                        continue;
                    }
                    
                    $seen[$resourceId] = true;
                    $totalCount++;
                    
                    $allSecondDegreeResources[] = [
                        'resource' => $resource
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