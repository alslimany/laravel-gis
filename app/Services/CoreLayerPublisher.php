<?php

namespace App\Services;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Models\Organization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CoreLayerPublisher implements LayerPublisher
{
    public function __construct(private GeoServerService $geoserver) {}

    public function upsertSqlViewLayer(
        Organization $organization,
        array $attributes,
        ?string $sql = null,
        ?array $sqlView = null,
        bool $syncGeoServer = true,
    ): array {
        $tableName = (string) ($attributes['table_name'] ?? '');
        if ($tableName === '') {
            throw new RuntimeException('LayerPublisher requires attributes.table_name.');
        }

        $existing = Layer::query()
            ->where('organization_id', $organization->getKey())
            ->where('table_name', $tableName)
            ->first();

        $ownerId = $attributes['user_id']
            ?? $existing?->user_id
            ?? $organization->user_id
            ?? $organization->members()->orderBy('id')->value('id');

        if ($ownerId === null) {
            throw new RuntimeException(
                "Cannot publish layer for organization {$organization->getKey()}: no user_id available."
            );
        }

        $layer = Layer::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'table_name' => $tableName,
            ],
            [
                'user_id' => $ownerId,
                'name' => (string) ($attributes['name'] ?? $tableName),
                'description' => $attributes['description'] ?? null,
                'geometry_type' => $attributes['geometry_type'] ?? null,
                'feature_count' => $attributes['feature_count'] ?? 0,
                'style_config' => $attributes['style_config'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ],
        );

        $published = false;
        if ($syncGeoServer && $sql !== null && $sqlView !== null) {
            $published = $this->publishSqlView($organization, $sql, $sqlView);
            if ($published) {
                $layer->markAsPublished(
                    (string) $sqlView['layerName'],
                    $organization->getGeoServerWorkspace(),
                );
            }
        }

        return ['layer' => $layer->fresh(), 'published' => $published];
    }

    /**
     * @param  array{
     *     layerName: string,
     *     geometryName?: string,
     *     geometryType?: string,
     *     srid?: int,
     *     keyColumn?: string,
     *     title?: string,
     *     abstract?: string,
     *     styleName?: string,
     *     styleSld?: string
     * }  $sqlView
     */
    protected function publishSqlView(Organization $organization, string $sql, array $sqlView): bool
    {
        $workspace = $organization->getGeoServerWorkspace();
        $datastore = (string) Config::get('geoserver.datastore', 'postgis');
        $layerName = (string) $sqlView['layerName'];

        try {
            if (! $this->geoserver->workspaceExists($workspace)) {
                $this->geoserver->createWorkspace($workspace);
            }
            if (! $this->geoserver->datastoreExists($workspace, $datastore)) {
                $this->geoserver->createPostGISDatastore($workspace, $datastore);
            }

            $ok = $this->geoserver->publishSqlView($workspace, $datastore, $layerName, $sql, [
                'geometryName' => $sqlView['geometryName'] ?? 'geom',
                'geometryType' => $sqlView['geometryType'] ?? 'MultiPolygon',
                'srid' => $sqlView['srid'] ?? 4326,
                'keyColumn' => $sqlView['keyColumn'] ?? 'id',
                'title' => $sqlView['title'] ?? $layerName,
                'abstract' => $sqlView['abstract'] ?? null,
            ]);

            if ($ok && ! empty($sqlView['styleName']) && ! empty($sqlView['styleSld'])) {
                $styleName = (string) $sqlView['styleName'];
                $this->geoserver->createOrUpdateStyle($workspace, $styleName, (string) $sqlView['styleSld']);
                $this->geoserver->applyStyleToLayer($workspace, $layerName, $styleName);
            }

            return $ok;
        } catch (Throwable $e) {
            Log::warning('core.layer_publisher.geoserver_failed', [
                'organization_id' => $organization->getKey(),
                'layer' => $layerName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
