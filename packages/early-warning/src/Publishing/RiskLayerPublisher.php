<?php

namespace Lumina\EarlyWarning\Publishing;

use App\Contracts\LayerPublisher;
use App\Models\Layer;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Models\RiskAssessment;

/**
 * Publish the current-hour basin risk map via Core LayerPublisher (Project Guide §2 #4).
 * SQL is built in code with a cast organization id — never from user input.
 */
class RiskLayerPublisher
{
    public const LAYER_KEY = 'basin-risk';

    /** GeoServer layer name; unique per org workspace, so it stays the same for every org. */
    public const GEOSERVER_LAYER = 'ew_basin_risk';

    /** Pre-fix shared Postgres name. Every org overwrote this one view (cross-tenant leak). */
    public const LEGACY_TABLE = 'ew_basin_risk';

    /** Postgres backing view / layers.table_name: one per org. */
    public static function tableFor(int $organizationId): string
    {
        return self::LEGACY_TABLE.'_'.(int) $organizationId;
    }

    public function __construct(public LayerPublisher $layers) {}

    /**
     * @return array{layer: Layer, published: bool}
     */
    public function publish(Organization $organization, bool $syncGeoServer = true, ?int $userId = null): array
    {
        $orgId = (int) $organization->getKey();
        $this->migrateLegacyLayerRow($orgId);
        $sql = $this->sqlForOrganization($orgId);
        $featureCount = (int) RiskAssessment::query()
            ->where('organization_id', $organization->getKey())
            ->where('valid_from', '<=', now())
            ->where('valid_until', '>', now())
            ->selectRaw('count(distinct basin_code) as aggregate')
            ->value('aggregate');

        $result = $this->layers->upsertSqlViewLayer(
            $organization,
            attributes: [
                'table_name' => self::tableFor($orgId),
                'name' => 'Basin flood risk (advisory)',
                'description' => 'Forecast-based basin risk levels. Decision support, not an official warning.',
                'geometry_type' => 'MultiPolygon',
                'feature_count' => $featureCount,
                'style_config' => $this->styleConfig(),
                'metadata' => [
                    'plugin' => 'early-warning',
                    'kind' => self::LAYER_KEY,
                    'advisory' => true, 'name_ar' => 'خطر فيضانات الأودية',
                    'description_key' => 'layers.desc.basin_risk',
                    'sql_view' => true,
                ],
                'user_id' => $userId,
            ],
            sql: $sql,
            sqlView: [
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
            syncGeoServer: $syncGeoServer,
        );

        $this->dropLegacySharedView();

        return $result;
    }

    /**
     * One-off rename of this org's pre-fix layer row (table_name ew_basin_risk) so the layer id,
     * maps and dashboards that point at it keep working on the new per-org view.
     */
    protected function migrateLegacyLayerRow(int $orgId): void
    {
        $alreadyMigrated = Layer::query()->where('organization_id', $orgId)
            ->where('table_name', self::tableFor($orgId))->exists();
        if ($alreadyMigrated) {
            return;
        }

        Layer::query()->where('organization_id', $orgId)
            ->where('table_name', self::LEGACY_TABLE)
            ->update(['table_name' => self::tableFor($orgId)]);
    }

    /**
     * Drop the old shared view once no layer row uses it. Only ever drops a VIEW (relkind 'v');
     * a real table with that name is left alone.
     */
    protected function dropLegacySharedView(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (Layer::query()->where('table_name', self::LEGACY_TABLE)->exists()) {
            return;
        }

        try {
            $isView = DB::scalar(
                "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relname = ? AND n.nspname = current_schema() AND c.relkind = 'v'",
                [self::LEGACY_TABLE],
            );
            if ((int) $isView === 1) {
                DB::statement('DROP VIEW IF EXISTS "'.self::LEGACY_TABLE.'"');
            }
        } catch (\Throwable $e) {
            Log::warning('early_warning.risk_layer.legacy_view_drop_failed', ['error' => $e->getMessage()]);
        }
    }

    public function sqlForOrganization(int $organizationId): string
    {
        $id = (int) $organizationId;

        // No trailing semicolon — GeoServer JDBC virtual tables reject it.
        return <<<SQL
SELECT r.id AS id,
       r.basin_code AS basin_code,
       b.name AS basin_name,
       b.name_ar AS basin_name_ar,
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
