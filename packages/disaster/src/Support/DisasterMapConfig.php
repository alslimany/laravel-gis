<?php

namespace Lumina\Disaster\Support;

use App\Models\Layer;
use App\Services\LayerExtent;
use App\Support\EnumLabel;
use Lumina\Disaster\Publishing\OpsLayerPublisher;

/**
 * Map props for the COP and Assets pages: only the current org's published operational
 * layers (basin risk, assets, incidents, closures), their styles, the data extent and an
 * Arabic legend. Tiles come from the normal /api/layers/{id}/tiles MVT route.
 */
class DisasterMapConfig
{
    /**
     * @return array{layers: list<array<string, mixed>>, bounds: array<int, float>|null}
     */
    public static function forOrganization(int $organizationId): array
    {
        $definitions = [
            'ew_basin_risk_'.$organizationId => ['title' => 'disaster.map_layer.basin_risk', 'prefix' => 'ew.risk_level'],
            'dm_org_assets_'.$organizationId => ['title' => 'disaster.map_layer.assets', 'prefix' => 'disaster.condition_grade'],
            OpsLayerPublisher::incidentsTable($organizationId) => ['title' => 'disaster.map_layer.incidents', 'prefix' => 'disaster.priority'],
            OpsLayerPublisher::closuresTable($organizationId) => ['title' => 'disaster.map_layer.closures', 'prefix' => 'disaster.severity'],
        ];

        $records = Layer::query()
            ->where('organization_id', $organizationId)
            ->whereIn('table_name', array_keys($definitions))
            ->get()
            ->keyBy('table_name');

        $extent = app(LayerExtent::class);
        $layers = [];
        $bounds = null;

        foreach ($definitions as $table => $definition) {
            $layer = $records->get($table);
            if (! $layer) {
                continue;
            }

            $style = is_array($layer->style_config) ? $layer->style_config : [];
            $legend = array_map(fn (array $entry) => [
                'value' => (string) ($entry['value'] ?? ''),
                'color' => $entry['color'] ?? null,
                'label' => EnumLabel::for((string) ($entry['value'] ?? ''), null, $definition['prefix']),
            ], is_array($style['uniqueValues'] ?? null) ? $style['uniqueValues'] : []);

            $layerBounds = $extent->for($layer);
            if ($layerBounds) {
                $bounds = $bounds === null ? $layerBounds : [
                    min($bounds[0], $layerBounds[0]), min($bounds[1], $layerBounds[1]),
                    max($bounds[2], $layerBounds[2]), max($bounds[3], $layerBounds[3]),
                ];
            }

            $layers[] = [
                'id' => $layer->id,
                'key' => $table,
                'title' => __($definition['title']),
                'geometry_type' => $layer->geometry_type,
                'style_config' => $style,
                'legend' => $legend,
                'bounds' => $layerBounds,
                'mvt_url' => "/api/layers/{$layer->id}/tiles/{z}/{x}/{y}.mvt",
            ];
        }

        return ['layers' => $layers, 'bounds' => $bounds];
    }
}
