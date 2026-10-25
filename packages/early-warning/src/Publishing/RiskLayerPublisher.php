<?php

namespace Lumina\EarlyWarning\Publishing;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Models\Organization;
use Lumina\EarlyWarning\Models\RiskAssessment;

/**
 * Publish the current-hour basin risk map via Core LayerPublisher (Project Guide §2 #4).
 * SQL is built in code with a cast organization id — never from user input.
 */
class RiskLayerPublisher
{
    public const LAYER_KEY = 'basin-risk';

    public const GEOSERVER_LAYER = 'ew_basin_risk';

    public function __construct(public LayerPublisher $layers) {}

    /**
     * @return array{layer: Layer, published: bool}
     */
    public function publish(Organization $organization, bool $syncGeoServer = true, ?int $userId = null): array
    {
        $sql = $this->sqlForOrganization((int) $organization->getKey());
        $featureCount = (int) RiskAssessment::query()
            ->where('organization_id', $organization->getKey())
            ->where('valid_from', '<=', now())
            ->where('valid_until', '>', now())
            ->selectRaw('count(distinct basin_code) as aggregate')
            ->value('aggregate');

        return $this->layers->upsertSqlViewLayer(
            $organization,
            [
                'table_name' => self::GEOSERVER_LAYER,
                'name' => 'Basin flood risk (advisory)',
                'description' => 'Forecast-based basin risk levels. Decision support, not an official warning.',
                'geometry_type' => 'MultiPolygon',
                'feature_count' => $featureCount,
                'style_config' => $this->styleConfig(),
                'metadata' => [
                    'plugin' => 'early-warning',
                    'kind' => self::LAYER_KEY,
                    'advisory' => true,
                    'sql_view' => true,
                ],
                'user_id' => $userId,
            ],
            $sql,
            [
                'layerName' => self::GEOSERVER_LAYER,
                'title' => "{$organization->name} — Basin flood risk (advisory)",
                'abstract' => 'Forecast-based decision support; not an official warning.',
                'geometryName' => 'geom',
                'geometryType' => 'MultiPolygon',
                'srid' => 4326,
                'keyColumn' => 'id',
                'styleName' => 'ew_basin_risk',
                'styleSld' => RiskLevelSld::xml(),
            ],
            $syncGeoServer,
        );
    }

    public function sqlForOrganization(int $organizationId): string
    {
        $id = (int) $organizationId;

        // No trailing semicolon — GeoServer JDBC virtual tables reject it.
        return <<<SQL
SELECT r.id AS id,
       r.basin_code AS basin_code,
       b.name AS basin_name,
       r.forecast_at AS forecast_at,
       r.risk_level AS risk_level,
       r.score AS score,
       b.geom AS geom
FROM ew_risk_assessments r
INNER JOIN ew_basins b ON b.basin_code = r.basin_code
WHERE r.organization_id = {$id}
  AND r.valid_from <= CURRENT_TIMESTAMP
  AND r.valid_until > CURRENT_TIMESTAMP
  AND r.forecast_at = (
    SELECT MIN(ra.forecast_at)
    FROM ew_risk_assessments ra
    WHERE ra.organization_id = {$id}
      AND ra.basin_code = r.basin_code
      AND ra.valid_from <= CURRENT_TIMESTAMP
      AND ra.valid_until > CURRENT_TIMESTAMP
  )
SQL;
    }

    /**
     * @return array<string, mixed>
     */
    protected function styleConfig(): array
    {
        return [
            'renderer' => 'unique',
            'field' => 'risk_level',
            'uniqueValues' => [
                ['value' => 'green', 'color' => '#22c55e'],
                ['value' => 'yellow', 'color' => '#eab308'],
                ['value' => 'orange', 'color' => '#f97316'],
                ['value' => 'red', 'color' => '#ef4444'],
            ],
            'symbol' => [
                'fillOpacity' => 0.55,
                'strokeColor' => '#1f2937',
                'strokeWidth' => 1,
            ],
        ];
    }
}
