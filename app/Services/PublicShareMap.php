<?php

namespace App\Services;

use App\Models\Layer;
use App\Models\Map;

class PublicShareMap
{
    /**
     * Guest payload for a public map.
     *
     * Vector tiles use the share-token route. Owner ids and authenticated
     * layer URLs are left off, and layers from another organization are omitted.
     *
     * @return array<string, mixed>
     */
    public function present(Map $map): array
    {
        $stored = array_values(array_filter(
            is_array($map->layers) ? $map->layers : [],
            'is_array'
        ));
        $allowed = $this->allowedLayerIds($map, $stored);
        $layers = [];

        foreach ($stored as $layer) {
            $presented = $this->presentLayer($map, $layer, $allowed);
            if ($presented !== null) {
                $layers[] = $presented;
            }
        }

        $viewport = is_array($map->viewport) ? $map->viewport : [];

        return [
            'id' => $map->id,
            'name' => $map->name,
            'description' => $map->description,
            'basemap' => $map->basemap ?: 'osm',
            'share_token' => $map->share_token,
            'viewport' => [
                'center' => $viewport['center'] ?? [0, 20],
                'zoom' => $viewport['zoom'] ?? 2,
                'rotation' => $viewport['rotation'] ?? 0,
            ],
            'layers' => $layers,
        ];
    }

    public function mapTileUrl(string $token, int|string $layerId): string
    {
        return url('/maps/shared/'.$token.'/tiles/'.$layerId.'/{z}/{x}/{y}.mvt');
    }

    public function dashboardTileUrl(string $token, int|string $layerId): string
    {
        return url('/dashboards/shared/'.$token.'/tiles/'.$layerId.'/{z}/{x}/{y}.mvt');
    }

    /**
     * @param  array<int, array<string, mixed>>  $layers
     * @return array<int, int>
     */
    protected function allowedLayerIds(Map $map, array $layers): array
    {
        $ids = [];
        foreach ($layers as $layer) {
            if (isset($layer['id']) && is_numeric($layer['id'])) {
                $ids[] = (int) $layer['id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return Layer::query()
            ->where('organization_id', $map->organization_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $layer
     * @param  array<int, int>  $allowed
     * @return array<string, mixed>|null
     */
    protected function presentLayer(Map $map, array $layer, array $allowed): ?array
    {
        $type = $layer['type'] ?? null;
        $numericId = isset($layer['id']) && is_numeric($layer['id']) ? (int) $layer['id'] : null;

        if ($numericId !== null && ! in_array($numericId, $allowed, true)) {
            return null;
        }

        if ($type === 'wms') {
            return $this->copy($layer, ['id', 'name', 'type', 'visible', 'opacity', 'url', 'layers', 'wmsParams', 'geometry_type']);
        }

        if ($numericId !== null && $map->share_token) {
            $presented = $this->copy($layer, ['id', 'name', 'type', 'visible', 'opacity', 'style_config', 'style', 'geometry_type']);
            $presented['type'] = $presented['type'] ?? 'mvt';
            $presented['mvtUrl'] = $this->mapTileUrl((string) $map->share_token, $numericId);
            $presented['visible'] = $layer['visible'] ?? true;

            return $presented;
        }

        $url = isset($layer['url']) ? (string) $layer['url'] : '';
        if ($url !== '' && ! $this->isInternalApiUrl($url)) {
            $presented = $this->copy($layer, ['id', 'name', 'type', 'visible', 'opacity', 'url', 'style_config', 'style', 'geometry_type']);
            $presented['visible'] = $layer['visible'] ?? true;

            return $presented;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $layer
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function copy(array $layer, array $keys): array
    {
        $copy = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $layer)) {
                $copy[$key] = $layer[$key];
            }
        }

        return $copy;
    }

    protected function isInternalApiUrl(string $url): bool
    {
        return str_contains($url, '/api/layers/') || str_starts_with($url, '/api/');
    }
}
