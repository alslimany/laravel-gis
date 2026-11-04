<?php

namespace Lumina\Disaster\Services;

use App\Contracts\LayerCatalog;
use App\Helpers\SpatialHelper;
use App\Models\OrganizationPlugin;
use Illuminate\Support\Collection;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\ExposureSummary;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Support\GeometryReader;

/**
 * @deprecated Use {@see ExposureComputer} for production PostGIS exposure + risk index.
 * This class remains as the SQLite / non-PostGIS legacy haversine path so package tests
 * can run without PostGIS. Do not call from new production code paths.
 */
class ProximityComputer
{
    public function __construct(protected LayerCatalog $layerCatalog) {}

    /**
     * @deprecated Prefer {@see ExposureComputer::compute()} which routes to PostGIS or this legacy path.
     *
     * @return int Number of summaries written
     */
    public function compute(
        int $organizationId,
        string $hazardKey,
        float $hazardLatitude,
        float $hazardLongitude,
        ?float $radiusM = null,
    ): int {
        return app(ExposureComputer::class)->compute(
            $organizationId,
            $hazardKey,
            $hazardLatitude,
            $hazardLongitude,
            $radiusM,
        );
    }

    /**
     * @deprecated Legacy haversine asset proximity for SQLite tests only.
     *
     * @return int Number of summaries written
     */
    public function computeLegacyHaversine(
        int $organizationId,
        string $hazardKey,
        float $hazardLatitude,
        float $hazardLongitude,
        ?float $radiusM = null,
        string $scenarioName = '',
    ): int {
        $radiusM ??= (float) config('disaster.exposure.default_hazard_radius_m', config('disaster.proximity.default_hazard_radius_m', 5000));
        $now = now();
        $written = 0;
        $hazardMeta = $this->hazardMeta($organizationId, $hazardKey, $hazardLatitude, $hazardLongitude, $radiusM);
        $hazardMeta['engine'] = 'legacy_haversine_sqlite';
        $hazardMeta['deprecated'] = true;

        foreach ($this->assets($organizationId) as $assetType => $rows) {
            foreach ($rows as $row) {
                $coords = GeometryReader::pointCoordinates($row->geom);
                if ($coords === null) {
                    continue;
                }

                $distance = SpatialHelper::distance(
                    $hazardLatitude,
                    $hazardLongitude,
                    $coords['latitude'],
                    $coords['longitude'],
                );

                $inHazard = $distance <= $radiusM;
                if ($hazardMeta['layer_feature_check'] === true) {
                    $inHazard = $this->assetWithinLayerFeatures(
                        $organizationId,
                        (int) $hazardMeta['layer_id'],
                        $coords['latitude'],
                        $coords['longitude'],
                        $radiusM,
                    ) || $inHazard;
                }

                ExposureSummary::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'hazard_key' => $hazardKey,
                        'scenario_name' => $scenarioName,
                        'asset_type' => $assetType,
                        'asset_id' => $row->id,
                    ],
                    [
                        'distance_m' => round($distance, 2),
                        'in_hazard' => $inHazard,
                        'computed_at' => $now,
                        'meta' => $hazardMeta,
                    ],
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    protected function hazardMeta(
        int $organizationId,
        string $hazardKey,
        float $hazardLatitude,
        float $hazardLongitude,
        float $radiusM,
    ): array {
        $settings = $this->pluginSettings($organizationId);
        $layerKey = match ($hazardKey) {
            'floodplain', 'flood' => 'floodplain_layer_id',
            'fault', 'seismic' => 'fault_layer_id',
            'wildfire' => 'wildfire_layer_id',
            default => null,
        };

        $layerId = $layerKey !== null ? ($settings[$layerKey] ?? null) : null;

        return [
            'hazard_key' => $hazardKey,
            'hazard_lat' => $hazardLatitude,
            'hazard_lon' => $hazardLongitude,
            'radius_m' => $radiusM,
            'layer_id' => $layerId,
            'layer_feature_check' => is_int($layerId) && $layerId > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pluginSettings(int $organizationId): array
    {
        $row = OrganizationPlugin::query()
            ->where('organization_id', $organizationId)
            ->where('plugin_key', 'disaster')
            ->first();

        return is_array($row?->settings) ? $row->settings : [];
    }

    protected function assetWithinLayerFeatures(
        int $organizationId,
        int $layerId,
        float $latitude,
        float $longitude,
        float $radiusM,
    ): bool {
        $layer = $this->layerCatalog->findForOrganization($organizationId, $layerId);
        if ($layer === null) {
            return false;
        }

        $page = $this->layerCatalog->listFeatures($layer, $organizationId, 500);
        $features = $page['features'] ?? [];
        if (! is_iterable($features)) {
            return false;
        }

        $items = is_object($features) && method_exists($features, 'items') ? $features->items() : $features;

        foreach ($items as $feature) {
            $geom = is_array($feature) ? ($feature['geom'] ?? $feature['geometry'] ?? null) : null;
            if (is_string($geom)) {
                $coords = GeometryReader::pointCoordinates($geom);
                if ($coords !== null) {
                    $distance = SpatialHelper::distance($latitude, $longitude, $coords['latitude'], $coords['longitude']);
                    if ($distance <= $radiusM) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, Collection<int, object>>
     */
    protected function assets(int $organizationId): array
    {
        return [
            'medical_facility' => MedicalFacility::query()
                ->forOrganization($organizationId)
                ->whereNotNull('geom')
                ->get(['id', 'geom']),
            'critical_site' => CriticalSite::query()
                ->forOrganization($organizationId)
                ->whereNotNull('geom')
                ->get(['id', 'geom']),
        ];
    }
}
