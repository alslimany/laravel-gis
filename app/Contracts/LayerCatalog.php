<?php

namespace App\Contracts;

use App\Models\Layer;
use Illuminate\Support\Collection;

/**
 * GIS Core → plugin contract: read layers/features by layer ID within an organization.
 * Plugins must not query Core tables ad hoc; use this service (Project Guide §6).
 */
interface LayerCatalog
{
    /**
     * Resolve a layer owned by the organization, or null when missing / wrong org.
     */
    public function findForOrganization(int $organizationId, int $layerId): ?Layer;

    /**
     * Layers for an organization, optionally filtered by metadata key/value.
     *
     * @param  array<string, mixed>  $metadataEquals
     * @return Collection<int, Layer>
     */
    public function forOrganization(int $organizationId, array $metadataEquals = []): Collection;

    /**
     * Attribute rows for a layer (geometry as WKT when PostGIS). Org ownership is enforced.
     *
     * @return array{columns: list<string>, features: mixed, shapes: list<array{type: string, count: int}>}
     */
    public function listFeatures(Layer $layer, int $organizationId, int $perPage = 25): array;
}
