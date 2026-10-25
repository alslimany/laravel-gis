<?php

namespace App\Services;

use App\Contracts\LayerCatalog;
use App\Models\Layer;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EloquentLayerCatalog implements LayerCatalog
{
    public function __construct(private FeatureService $features) {}

    public function findForOrganization(int $organizationId, int $layerId): ?Layer
    {
        return Layer::query()
            ->where('organization_id', $organizationId)
            ->whereKey($layerId)
            ->first();
    }

    public function forOrganization(int $organizationId, array $metadataEquals = []): Collection
    {
        $query = Layer::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name');

        foreach ($metadataEquals as $key => $value) {
            $query->where("metadata->{$key}", $value);
        }

        return $query->get();
    }

    public function listFeatures(Layer $layer, int $organizationId, int $perPage = 25): array
    {
        if ((int) $layer->organization_id !== $organizationId) {
            throw new NotFoundHttpException('Layer not found for organization.');
        }

        return $this->features->list($layer, $perPage);
    }
}
