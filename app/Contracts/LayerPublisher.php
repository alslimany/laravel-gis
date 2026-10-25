<?php

namespace App\Contracts;

use App\Models\Layer;
use App\Models\Organization;

/**
 * GIS Core → plugin contract: create/update a Layer and publish/update it on GeoServer
 * (including SQL View layers). Plugins return outputs through Core (Project Guide §2 #4).
 */
interface LayerPublisher
{
    /**
     * Upsert an org-scoped layer and optionally publish a GeoServer SQL view.
     *
     * @param  array{
     *     table_name: string,
     *     name: string,
     *     description?: string|null,
     *     geometry_type?: string|null,
     *     feature_count?: int|null,
     *     style_config?: array<string, mixed>|null,
     *     metadata?: array<string, mixed>|null,
     *     user_id?: int|null
     * }  $attributes
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
     * }|null  $sqlView  When null, only the Layer row is upserted (no GeoServer call).
     * @return array{layer: Layer, published: bool}
     */
    public function upsertSqlViewLayer(
        Organization $organization,
        array $attributes,
        ?string $sql = null,
        ?array $sqlView = null,
        bool $syncGeoServer = true,
    ): array;
}
