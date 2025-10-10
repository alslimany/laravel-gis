<?php

namespace Tests\TestHelpers;

use GuzzleHttp\Psr7\Response;

/**
 * Helper class for mocking GeoServer API responses
 */
class MockGeoServerResponse
{
    /**
     * Create a successful workspace creation response
     */
    public static function workspaceCreated(string $workspaceName): Response
    {
        return new Response(201, [
            'Content-Type' => 'application/json',
            'Location' => "/geoserver/rest/workspaces/{$workspaceName}",
        ]);
    }

    /**
     * Create a workspace already exists response
     */
    public static function workspaceExists(string $workspaceName): Response
    {
        return new Response(409, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'error' => "Workspace '{$workspaceName}' already exists",
        ]));
    }

    /**
     * Create a successful datastore creation response
     */
    public static function datastoreCreated(string $datastoreName): Response
    {
        return new Response(201, [
            'Content-Type' => 'application/json',
            'Location' => "/geoserver/rest/workspaces/test/datastores/{$datastoreName}",
        ]);
    }

    /**
     * Create a successful layer publish response
     */
    public static function layerPublished(string $layerName): Response
    {
        return new Response(201, [
            'Content-Type' => 'application/json',
            'Location' => "/geoserver/rest/workspaces/test/datastores/test_ds/featuretypes/{$layerName}",
        ]);
    }

    /**
     * Create a layer list response
     */
    public static function layerList(array $layers = []): Response
    {
        $featureTypes = array_map(fn($layer) => [
            'name' => $layer,
            'href' => "http://localhost:8080/geoserver/rest/workspaces/test/datastores/test_ds/featuretypes/{$layer}.json",
        ], $layers);

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'featureTypes' => [
                'featureType' => $featureTypes,
            ],
        ]));
    }

    /**
     * Create a layer details response
     */
    public static function layerDetails(string $layerName, array $attributes = []): Response
    {
        $defaultAttributes = [
            ['name' => 'id', 'type' => 'integer'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'geometry', 'type' => 'geometry'],
        ];

        $attrs = empty($attributes) ? $defaultAttributes : $attributes;

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'featureType' => [
                'name' => $layerName,
                'nativeName' => $layerName,
                'title' => ucfirst($layerName),
                'srs' => 'EPSG:4326',
                'attributes' => [
                    'attribute' => $attrs,
                ],
            ],
        ]));
    }

    /**
     * Create a style list response
     */
    public static function styleList(array $styles = []): Response
    {
        $styleItems = array_map(fn($style) => [
            'name' => $style,
            'href' => "http://localhost:8080/geoserver/rest/styles/{$style}.json",
        ], $styles);

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'styles' => [
                'style' => $styleItems,
            ],
        ]));
    }

    /**
     * Create a successful style creation response
     */
    public static function styleCreated(string $styleName): Response
    {
        return new Response(201, [
            'Content-Type' => 'application/json',
            'Location' => "/geoserver/rest/styles/{$styleName}",
        ]);
    }

    /**
     * Create a WFS GetFeature response
     */
    public static function wfsGetFeature(array $features = []): Response
    {
        $geoJson = [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode($geoJson));
    }

    /**
     * Create a WMS GetCapabilities response
     */
    public static function wmsGetCapabilities(array $layers = []): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<WMS_Capabilities version="1.3.0">';
        $xml .= '<Service><Name>WMS</Name><Title>GeoServer WMS</Title></Service>';
        $xml .= '<Capability>';
        $xml .= '<Layer>';

        foreach ($layers as $layer) {
            $xml .= "<Layer><Name>{$layer}</Name><Title>{$layer}</Title></Layer>";
        }

        $xml .= '</Layer>';
        $xml .= '</Capability>';
        $xml .= '</WMS_Capabilities>';

        return new Response(200, [
            'Content-Type' => 'application/xml',
        ], $xml);
    }

    /**
     * Create an error response
     */
    public static function error(int $statusCode, string $message): Response
    {
        return new Response($statusCode, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'error' => $message,
        ]));
    }

    /**
     * Create a successful delete response
     */
    public static function deleteSuccess(): Response
    {
        return new Response(200);
    }

    /**
     * Create a not found response
     */
    public static function notFound(string $resource): Response
    {
        return new Response(404, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'error' => "Resource '{$resource}' not found",
        ]));
    }

    /**
     * Create a workspace list response
     */
    public static function workspaceList(array $workspaces = []): Response
    {
        $items = array_map(fn($ws) => [
            'name' => $ws,
            'href' => "http://localhost:8080/geoserver/rest/workspaces/{$ws}.json",
        ], $workspaces);

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'workspaces' => [
                'workspace' => $items,
            ],
        ]));
    }
}
