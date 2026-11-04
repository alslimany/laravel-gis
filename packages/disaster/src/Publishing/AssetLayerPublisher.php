<?php

namespace Lumina\Disaster\Publishing;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Models\Organization;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\MedicalFacility;

/**
 * Optional publish of org disaster asset points via Core LayerPublisher.
 */
class AssetLayerPublisher
{
    public const LAYER_KEY = 'disaster-assets';

    /** GeoServer layer name (unique per org workspace, like ew_basin_risk). */
    public const GEOSERVER_LAYER = 'dm_org_assets';

    /**
     * Condition grade → map color hints (consumers may ignore).
     *
     * @var array<string, string>
     */
    public const CONDITION_GRADE_COLORS = [
        'good' => '#22c55e',
        'fair' => '#eab308',
        'poor' => '#f97316',
        'critical' => '#ef4444',
    ];

    public function __construct(public LayerPublisher $layers) {}

    /**
     * @return array{layer: Layer, published: bool}
     */
    public function publish(Organization $organization, bool $syncGeoServer = true, ?int $userId = null): array
    {
        $orgId = (int) $organization->getKey();
        $featureCount = MedicalFacility::query()->forOrganization($orgId)->count()
            + CriticalSite::query()->forOrganization($orgId)->count();

        $sql = $this->sqlForOrganization($orgId);

        return $this->layers->upsertSqlViewLayer(
            $organization,
            [
                'table_name' => 'dm_org_assets_'.$orgId,
                'name' => 'Disaster assets (medical + critical)',
                'description' => 'Org-scoped disaster prevention assets. Decision support only.',
                'geometry_type' => 'Point',
                'feature_count' => $featureCount,
                'style_config' => [
                    ...OpsLayerPublisher::uniqueStyle('condition_grade', self::CONDITION_GRADE_COLORS, 'disaster-assets'),
                    'condition_grade_colors' => self::CONDITION_GRADE_COLORS,
                ],
                'metadata' => [
                    'plugin' => 'disaster',
                    'kind' => self::LAYER_KEY, 'name_ar' => 'المرافق الحيوية',
                    'description_key' => 'layers.desc.assets',
                    'sql_view' => true,
                ],
                'user_id' => $userId,
            ],
            $sql,
            // Was passed positionally into ?array $sqlView (TypeError). Named args match the contract.
            sqlView: [
                'layerName' => self::GEOSERVER_LAYER,
                'title' => "{$organization->name} — Disaster assets",
                'abstract' => 'Org-scoped disaster prevention assets. Decision support only.',
                'geometryName' => 'geom',
                'geometryType' => 'Point',
                'srid' => 4326,
                // ids repeat across the two UNIONed tables; feature_key is unique.
                'keyColumn' => 'feature_key',
            ],
            syncGeoServer: $syncGeoServer,
        );
    }

    protected function sqlForOrganization(int $organizationId): string
    {
        $id = (int) $organizationId;

        // Critical sites may be polygons/lines; the layer is declared Point, so both halves are
        // normalised with ST_PointOnSurface (always inside the shape, unlike a centroid).

        return <<<SQL
SELECT 'mf-' || id as feature_key, id, name, 'medical' as asset_kind, CAST(NULL AS TEXT) as condition_grade, ST_PointOnSurface(geom)::geometry(Point,4326) AS geom
FROM dm_medical_facilities
WHERE organization_id = {$id} AND geom IS NOT NULL
UNION ALL
SELECT 'cs-' || id as feature_key, id, name, type as asset_kind, (
    SELECT c.condition_grade
    FROM dm_infrastructure_conditions c
    WHERE c.site_id = dm_critical_sites.id
    ORDER BY c.last_inspection_at DESC NULLS LAST, c.id DESC
    LIMIT 1
) as condition_grade, ST_PointOnSurface(geom)::geometry(Point,4326) AS geom
FROM dm_critical_sites
WHERE organization_id = {$id} AND geom IS NOT NULL
SQL;
    }
}
