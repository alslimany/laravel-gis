<?php

namespace App\Traits;

use App\Jobs\DeleteLayerFromGeoServer;
use App\Jobs\PublishLayerToGeoServer;
use App\Jobs\UpdateLayerStyle;
use Illuminate\Support\Facades\Config;

trait HasGeoServerLayers
{
    /**
     * Get the GeoServer workspace name for this model.
     */
    public function getGeoServerWorkspace(): string
    {
        return 'org_'.$this->id;
    }

    /**
     * Publish a layer to GeoServer for this organization.
     */
    public function publishLayerToGeoServer(string $tableName, array $options = []): void
    {
        $workspace = $this->getGeoServerWorkspace();
        $datastore = Config::get('geoserver.datastore');

        $defaultOptions = [
            'title' => "{$this->name} - ".ucfirst($tableName),
            'abstract' => ucfirst($tableName)." layer for organization {$this->name}",
            'srs' => 'EPSG:4326',
        ];

        PublishLayerToGeoServer::dispatch(
            $workspace,
            $datastore,
            $tableName,
            array_merge($defaultOptions, $options)
        );
    }

    /**
     * Delete a layer from GeoServer for this organization.
     */
    public function deleteLayerFromGeoServer(string $layerName): void
    {
        $workspace = $this->getGeoServerWorkspace();
        $datastore = Config::get('geoserver.datastore');

        DeleteLayerFromGeoServer::dispatch($workspace, $datastore, $layerName);
    }

    /**
     * Update layer style in GeoServer.
     */
    public function updateLayerStyle(string $layerName, string $styleName, ?string $sldContent = null): void
    {
        $workspace = $this->getGeoServerWorkspace();

        UpdateLayerStyle::dispatch($workspace, $layerName, $styleName, $sldContent);
    }

    /**
     * Publish all organization layers to GeoServer.
     */
    public function publishAllLayers(): void
    {
        // Publish projects layer
        $this->publishLayerToGeoServer('projects', [
            'title' => "{$this->name} - Projects",
            'abstract' => 'Project bounding boxes',
        ]);

        // Publish users layer
        $this->publishLayerToGeoServer('users', [
            'title' => "{$this->name} - Users",
            'abstract' => 'User locations',
        ]);
    }
}
