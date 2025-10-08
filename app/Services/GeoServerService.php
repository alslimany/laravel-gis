<?php

namespace App\Services;

use App\Exceptions\GeoServerException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class GeoServerService
{
    protected Client $client;

    protected string $baseUrl;

    protected string $restUrl;

    protected array $auth;

    /**
     * Create a new GeoServer service instance.
     */
    public function __construct()
    {
        $this->baseUrl = rtrim(Config::get('geoserver.url'), '/');
        $this->restUrl = $this->baseUrl.'/rest';

        $this->auth = [
            Config::get('geoserver.admin_user'),
            Config::get('geoserver.admin_password'),
        ];

        $this->client = new Client([
            'timeout' => Config::get('geoserver.timeout', 30),
            'http_errors' => false,
        ]);
    }

    /**
     * Check if a workspace exists.
     */
    public function workspaceExists(string $workspace): bool
    {
        try {
            $response = $this->client->get(
                "{$this->restUrl}/workspaces/{$workspace}.json",
                ['auth' => $this->auth]
            );

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error("Error checking workspace: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create a new workspace.
     *
     * @throws GeoServerException
     */
    public function createWorkspace(string $workspace, ?string $namespaceUri = null): bool
    {
        if ($this->workspaceExists($workspace)) {
            Log::info("Workspace {$workspace} already exists");

            return true;
        }

        try {
            $namespaceUri = $namespaceUri ?? "http://example.com/{$workspace}";

            $payload = [
                'workspace' => [
                    'name' => $workspace,
                    'isolated' => false,
                ],
            ];

            $response = $this->client->post(
                "{$this->restUrl}/workspaces",
                [
                    'auth' => $this->auth,
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $payload,
                ]
            );

            if ($response->getStatusCode() === 201) {
                Log::info("Workspace {$workspace} created successfully");

                return true;
            }

            throw GeoServerException::workspaceCreationFailed(
                $workspace,
                new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}")
            );
        } catch (GuzzleException $e) {
            throw GeoServerException::workspaceCreationFailed($workspace, $e);
        }
    }

    /**
     * Delete a workspace.
     *
     * @throws GeoServerException
     */
    public function deleteWorkspace(string $workspace, bool $recurse = true): bool
    {
        try {
            $response = $this->client->delete(
                "{$this->restUrl}/workspaces/{$workspace}",
                [
                    'auth' => $this->auth,
                    'query' => ['recurse' => $recurse ? 'true' : 'false'],
                ]
            );

            if (in_array($response->getStatusCode(), [200, 404])) {
                Log::info("Workspace {$workspace} deleted successfully");

                return true;
            }

            throw new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}");
        } catch (GuzzleException $e) {
            throw GeoServerException::workspaceCreationFailed($workspace, $e);
        }
    }

    /**
     * Check if a datastore exists.
     */
    public function datastoreExists(string $workspace, string $datastore): bool
    {
        try {
            $response = $this->client->get(
                "{$this->restUrl}/workspaces/{$workspace}/datastores/{$datastore}.json",
                ['auth' => $this->auth]
            );

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error("Error checking datastore: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Create a PostGIS datastore.
     *
     * @throws GeoServerException
     */
    public function createPostGISDatastore(
        string $workspace,
        string $datastoreName,
        ?array $connectionParams = null
    ): bool {
        if ($this->datastoreExists($workspace, $datastoreName)) {
            Log::info("Datastore {$datastoreName} already exists in workspace {$workspace}");

            return true;
        }

        try {
            $connectionParams = $connectionParams ?? Config::get('geoserver.postgis');

            $payload = [
                'dataStore' => [
                    'name' => $datastoreName,
                    'type' => 'PostGIS',
                    'enabled' => true,
                    'connectionParameters' => [
                        'entry' => [
                            ['@key' => 'host', '$' => $connectionParams['host']],
                            ['@key' => 'port', '$' => (string) $connectionParams['port']],
                            ['@key' => 'database', '$' => $connectionParams['database']],
                            ['@key' => 'schema', '$' => $connectionParams['schema'] ?? 'public'],
                            ['@key' => 'user', '$' => $connectionParams['user']],
                            ['@key' => 'passwd', '$' => $connectionParams['password']],
                            ['@key' => 'dbtype', '$' => 'postgis'],
                            ['@key' => 'Expose primary keys', '$' => 'true'],
                        ],
                    ],
                ],
            ];

            $response = $this->client->post(
                "{$this->restUrl}/workspaces/{$workspace}/datastores",
                [
                    'auth' => $this->auth,
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $payload,
                ]
            );

            if ($response->getStatusCode() === 201) {
                Log::info("Datastore {$datastoreName} created successfully in workspace {$workspace}");

                return true;
            }

            throw GeoServerException::datastoreCreationFailed(
                $datastoreName,
                new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}")
            );
        } catch (GuzzleException $e) {
            throw GeoServerException::datastoreCreationFailed($datastoreName, $e);
        }
    }

    /**
     * Check if a layer exists.
     */
    public function layerExists(string $workspace, string $layer): bool
    {
        try {
            $response = $this->client->get(
                "{$this->restUrl}/workspaces/{$workspace}/layers/{$layer}.json",
                ['auth' => $this->auth]
            );

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error("Error checking layer: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Publish a PostGIS table as a layer.
     *
     * @throws GeoServerException
     */
    public function publishLayer(
        string $workspace,
        string $datastore,
        string $tableName,
        array $options = []
    ): bool {
        try {
            // First create the featureType
            $payload = [
                'featureType' => [
                    'name' => $tableName,
                    'nativeName' => $tableName,
                    'title' => $options['title'] ?? $tableName,
                    'abstract' => $options['abstract'] ?? "Layer {$tableName}",
                    'enabled' => true,
                    'srs' => $options['srs'] ?? 'EPSG:4326',
                    'projectionPolicy' => 'FORCE_DECLARED',
                ],
            ];

            // Add native bounding box if provided
            if (isset($options['nativeBoundingBox'])) {
                $payload['featureType']['nativeBoundingBox'] = $options['nativeBoundingBox'];
            }

            // Add lat/lon bounding box if provided
            if (isset($options['latLonBoundingBox'])) {
                $payload['featureType']['latLonBoundingBox'] = $options['latLonBoundingBox'];
            }

            $response = $this->client->post(
                "{$this->restUrl}/workspaces/{$workspace}/datastores/{$datastore}/featuretypes",
                [
                    'auth' => $this->auth,
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $payload,
                ]
            );

            if ($response->getStatusCode() === 201) {
                Log::info("Layer {$tableName} published successfully in workspace {$workspace}");

                // Apply default style if specified
                if (isset($options['defaultStyle'])) {
                    $this->applyStyleToLayer($workspace, $tableName, $options['defaultStyle']);
                }

                return true;
            }

            throw GeoServerException::layerPublishFailed(
                $tableName,
                new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}")
            );
        } catch (GuzzleException $e) {
            throw GeoServerException::layerPublishFailed($tableName, $e);
        }
    }

    /**
     * Delete a layer from GeoServer.
     *
     * @throws GeoServerException
     */
    public function deleteLayer(
        string $workspace,
        string $datastore,
        string $layerName,
        bool $recurse = true
    ): bool {
        try {
            // Delete the featuretype
            $response = $this->client->delete(
                "{$this->restUrl}/workspaces/{$workspace}/datastores/{$datastore}/featuretypes/{$layerName}",
                [
                    'auth' => $this->auth,
                    'query' => ['recurse' => $recurse ? 'true' : 'false'],
                ]
            );

            if (in_array($response->getStatusCode(), [200, 404])) {
                Log::info("Layer {$layerName} deleted successfully from workspace {$workspace}");

                return true;
            }

            throw GeoServerException::layerDeletionFailed(
                $layerName,
                new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}")
            );
        } catch (GuzzleException $e) {
            throw GeoServerException::layerDeletionFailed($layerName, $e);
        }
    }

    /**
     * Apply a style to a layer.
     *
     * @throws GeoServerException
     */
    public function applyStyleToLayer(string $workspace, string $layerName, string $styleName): bool
    {
        try {
            $payload = [
                'layer' => [
                    'defaultStyle' => [
                        'name' => $styleName,
                    ],
                ],
            ];

            $response = $this->client->put(
                "{$this->restUrl}/layers/{$workspace}:{$layerName}",
                [
                    'auth' => $this->auth,
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $payload,
                ]
            );

            if ($response->getStatusCode() === 200) {
                Log::info("Style {$styleName} applied to layer {$layerName}");

                return true;
            }

            throw GeoServerException::styleUpdateFailed(
                $styleName,
                new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}")
            );
        } catch (GuzzleException $e) {
            throw GeoServerException::styleUpdateFailed($styleName, $e);
        }
    }

    /**
     * Create or update a style with SLD content.
     *
     * @throws GeoServerException
     */
    public function createOrUpdateStyle(string $workspace, string $styleName, string $sldContent): bool
    {
        try {
            // Check if style exists
            $existsResponse = $this->client->get(
                "{$this->restUrl}/workspaces/{$workspace}/styles/{$styleName}.json",
                ['auth' => $this->auth]
            );

            $method = $existsResponse->getStatusCode() === 200 ? 'put' : 'post';
            $url = $existsResponse->getStatusCode() === 200
                ? "{$this->restUrl}/workspaces/{$workspace}/styles/{$styleName}"
                : "{$this->restUrl}/workspaces/{$workspace}/styles";

            $options = [
                'auth' => $this->auth,
                'headers' => ['Content-Type' => 'application/vnd.ogc.sld+xml'],
                'body' => $sldContent,
            ];

            if ($method === 'post') {
                $options['query'] = ['name' => $styleName];
            }

            $response = $this->client->$method($url, $options);

            if (in_array($response->getStatusCode(), [200, 201])) {
                Log::info("Style {$styleName} created/updated successfully in workspace {$workspace}");

                return true;
            }

            throw GeoServerException::styleUpdateFailed(
                $styleName,
                new \Exception("Status code: {$response->getStatusCode()}, Body: {$response->getBody()}")
            );
        } catch (GuzzleException $e) {
            throw GeoServerException::styleUpdateFailed($styleName, $e);
        }
    }

    /**
     * Get the default SLD style for a simple point layer.
     */
    public function getDefaultPointStyle(string $styleName, array $options = []): string
    {
        $color = $options['color'] ?? '#FF0000';
        $size = $options['size'] ?? 6;

        return <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0" xmlns="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc">
  <NamedLayer>
    <Name>{$styleName}</Name>
    <UserStyle>
      <Title>Default Point Style</Title>
      <FeatureTypeStyle>
        <Rule>
          <PointSymbolizer>
            <Graphic>
              <Mark>
                <WellKnownName>circle</WellKnownName>
                <Fill>
                  <CssParameter name="fill">{$color}</CssParameter>
                </Fill>
                <Stroke>
                  <CssParameter name="stroke">#000000</CssParameter>
                  <CssParameter name="stroke-width">1</CssParameter>
                </Stroke>
              </Mark>
              <Size>{$size}</Size>
            </Graphic>
          </PointSymbolizer>
        </Rule>
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;
    }

    /**
     * Get the default SLD style for a simple polygon layer.
     */
    public function getDefaultPolygonStyle(string $styleName, array $options = []): string
    {
        $fillColor = $options['fillColor'] ?? '#AAAAAA';
        $strokeColor = $options['strokeColor'] ?? '#000000';
        $strokeWidth = $options['strokeWidth'] ?? 1;
        $fillOpacity = $options['fillOpacity'] ?? 0.5;

        return <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0" xmlns="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc">
  <NamedLayer>
    <Name>{$styleName}</Name>
    <UserStyle>
      <Title>Default Polygon Style</Title>
      <FeatureTypeStyle>
        <Rule>
          <PolygonSymbolizer>
            <Fill>
              <CssParameter name="fill">{$fillColor}</CssParameter>
              <CssParameter name="fill-opacity">{$fillOpacity}</CssParameter>
            </Fill>
            <Stroke>
              <CssParameter name="stroke">{$strokeColor}</CssParameter>
              <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
            </Stroke>
          </PolygonSymbolizer>
        </Rule>
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;
    }

    /**
     * Execute a request with retry logic.
     *
     * @param  int|null  $retryDelay  milliseconds
     * @return mixed
     *
     * @throws GeoServerException
     */
    protected function executeWithRetry(callable $callback, ?int $retryTimes = null, ?int $retryDelay = null)
    {
        $retryTimes = $retryTimes ?? Config::get('geoserver.retry_times', 3);
        $retryDelay = $retryDelay ?? Config::get('geoserver.retry_delay', 1000);

        $lastException = null;

        for ($i = 0; $i < $retryTimes; $i++) {
            try {
                return $callback();
            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning('Retry attempt '.($i + 1).' failed: '.$e->getMessage());

                if ($i < $retryTimes - 1) {
                    usleep($retryDelay * 1000);
                }
            }
        }

        throw GeoServerException::connectionFailed($lastException);
    }
}
