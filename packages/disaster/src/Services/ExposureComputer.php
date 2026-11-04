<?php

namespace Lumina\Disaster\Services;

use App\Contracts\LayerCatalog;
use App\Helpers\GeometryColumnHelper;
use App\Helpers\SpatialHelper;
use App\Models\Layer;
use App\Models\OrganizationPlugin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\ExposureSummary;
use Lumina\Disaster\Models\InfrastructureCondition;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Support\GeometryReader;

/**
 * Spatial exposure engine: PostGIS ST_Intersects / ST_DWithin / ST_Area against Core
 * hazard polygons (LayerCatalog), plus risk_index rollups and dam-failure scenarios.
 *
 * SQLite / non-PostGIS drivers use a clearly marked legacy haversine path so package
 * tests still run without PostGIS.
 */
class ExposureComputer
{
    public function __construct(
        protected LayerCatalog $layerCatalog,
        protected RiskIndexCalculator $riskIndex,
        protected ProximityComputer $legacyProximity,
    ) {}

    public function usesPostgis(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Asset-level exposure vs hazard anchor and optional Core hazard layer.
     * Production: PostGIS. Tests/SQLite: deprecated haversine via ProximityComputer.
     *
     * @return int Number of asset summary rows written
     */
    public function compute(
        int $organizationId,
        string $hazardKey,
        float $hazardLatitude,
        float $hazardLongitude,
        ?float $radiusM = null,
        string $scenarioName = '',
    ): int {
        $radiusM ??= (float) config('disaster.exposure.default_hazard_radius_m', config('disaster.proximity.default_hazard_radius_m', 5000));
        $scenarioName = $this->normalizeScenarioName($scenarioName);

        if (! $this->usesPostgis()) {
            // @deprecated legacy SQLite / non-PostGIS fallback — do not use in production.
            $written = $this->legacyProximity->computeLegacyHaversine(
                $organizationId,
                $hazardKey,
                $hazardLatitude,
                $hazardLongitude,
                $radiusM,
                $scenarioName,
            );
            $this->computeZone(
                organizationId: $organizationId,
                hazardKey: $hazardKey,
                hazardLatitude: $hazardLatitude,
                hazardLongitude: $hazardLongitude,
                radiusM: $radiusM,
                scenarioName: $scenarioName,
                engineNote: 'legacy_asset_compute_zone_rollup',
            );

            return $written;
        }

        return $this->computeAssetsPostgis(
            $organizationId,
            $hazardKey,
            $hazardLatitude,
            $hazardLongitude,
            $radiusM,
            $scenarioName,
        );
    }

    /**
     * Zone rollup for a hazard layer (optional feature) or point-buffer when no layer.
     * Writes asset_type=zone row with population/roads/assets + risk_index.
     */
    public function computeZone(
        int $organizationId,
        string $hazardKey,
        ?int $hazardZoneLayerId = null,
        ?int $hazardFeatureId = null,
        ?float $hazardLatitude = null,
        ?float $hazardLongitude = null,
        ?float $radiusM = null,
        ?float $hazardLevel = null,
        string $scenarioName = '',
        ?string $engineNote = null,
    ): ExposureSummary {
        $scenarioName = $this->normalizeScenarioName($scenarioName);
        $settings = $this->pluginSettings($organizationId);
        $weightsOverride = is_array($settings['risk_weights'] ?? null) ? $settings['risk_weights'] : [];
        $radiusM ??= (float) config('disaster.exposure.default_hazard_radius_m', 5000);
        $hazardLevel ??= (float) ($settings['default_hazard_level'] ?? config('disaster.risk.default_hazard_level', 0.7));

        $resolvedLayerId = $hazardZoneLayerId ?? $this->hazardLayerIdFromSettings($settings, $hazardKey);

        if ($this->usesPostgis() && $resolvedLayerId !== null) {
            $metrics = $this->postgisZoneMetrics(
                $organizationId,
                $resolvedLayerId,
                $hazardFeatureId,
                $settings,
            );
        } elseif ($this->usesPostgis() && $hazardLatitude !== null && $hazardLongitude !== null) {
            $metrics = $this->postgisPointBufferMetrics(
                $organizationId,
                $hazardLatitude,
                $hazardLongitude,
                $radiusM,
                $settings,
            );
        } else {
            // @deprecated legacy SQLite fallback — approximate from asset haversine + LayerCatalog WKT.
            $metrics = $this->legacyZoneMetrics(
                $organizationId,
                $hazardKey,
                $hazardLatitude,
                $hazardLongitude,
                $radiusM,
                $resolvedLayerId,
                $hazardFeatureId,
                $settings,
            );
        }

        $vulnerability = $this->riskIndex->vulnerabilityFromGrades($metrics['condition_grades'], $weightsOverride);
        $risk = $this->riskIndex->calculate($hazardLevel, [
            'population' => $metrics['population_exposed'],
            'buildings' => $metrics['building_count'],
            'roads_m' => $metrics['road_length_m'],
            'assets' => $metrics['assets_exposed'],
        ], $vulnerability, $weightsOverride);

        $zoneAssetType = (string) config('disaster.exposure.zone_asset_type', 'zone');
        $assetId = $hazardFeatureId ?? 0;
        $now = now();

        return ExposureSummary::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'hazard_key' => $hazardKey,
                'scenario_name' => $scenarioName,
                'asset_type' => $zoneAssetType,
                'asset_id' => $assetId,
            ],
            [
                'distance_m' => null,
                'in_hazard' => ($metrics['assets_exposed'] ?? 0) > 0
                    || ($metrics['population_exposed'] ?? 0) > 0
                    || ($metrics['building_count'] ?? 0) > 0,
                'computed_at' => $now,
                'hazard_zone_layer_id' => $resolvedLayerId,
                'hazard_feature_id' => $hazardFeatureId,
                'population_exposed' => (int) $metrics['population_exposed'],
                'road_length_m' => round((float) $metrics['road_length_m'], 2),
                'building_count' => (int) $metrics['building_count'],
                'medical_facility_count' => (int) $metrics['medical_facility_count'],
                'critical_site_count' => (int) $metrics['critical_site_count'],
                'staging_area_count' => (int) $metrics['staging_area_count'],
                'assets_exposed' => (int) $metrics['assets_exposed'],
                'hazard_level' => $risk['hazard_level'],
                'exposure_score' => $risk['exposure_score'],
                'vulnerability_score' => $risk['vulnerability_score'],
                'risk_index' => $risk['risk_index'],
                'weights_snapshot' => $risk['weights_snapshot'],
                'meta' => [
                    'engine' => $this->usesPostgis() ? 'postgis' : 'legacy_haversine_sqlite',
                    'engine_note' => $engineNote,
                    'hazard_lat' => $hazardLatitude,
                    'hazard_lon' => $hazardLongitude,
                    'radius_m' => $radiusM,
                    'deprecated_fallback' => ! $this->usesPostgis(),
                ],
            ],
        );
    }

    /**
     * "What if this dam fails" using CriticalSite.downstream_zone_layer_id.
     */
    public function computeDamFailureScenario(
        int $organizationId,
        int $criticalSiteId,
        string $scenarioName,
        ?float $hazardLevel = null,
    ): ExposureSummary {
        $site = CriticalSite::query()
            ->forOrganization($organizationId)
            ->whereKey($criticalSiteId)
            ->firstOrFail();

        $layerId = $site->downstream_zone_layer_id;
        if ($layerId === null || $layerId < 1) {
            throw new \InvalidArgumentException('Critical site has no downstream_zone_layer_id for scenario runs.');
        }

        $type = is_string($site->type) ? $site->type : ($site->type?->value ?? 'dam');
        $hazardKey = 'dam_failure:'.$type;

        return $this->computeZone(
            organizationId: $organizationId,
            hazardKey: $hazardKey,
            hazardZoneLayerId: (int) $layerId,
            hazardFeatureId: null,
            hazardLevel: $hazardLevel ?? 1.0,
            scenarioName: $scenarioName,
            engineNote: 'dam_failure_scenario',
        );
    }

    /**
     * Ranked zone summaries by risk_index (highest first).
     *
     * @return Collection<int, ExposureSummary>
     */
    public function ranked(int $organizationId, ?string $hazardKey = null, int $limit = 50): Collection
    {
        $zoneType = (string) config('disaster.exposure.zone_asset_type', 'zone');
        $query = ExposureSummary::query()
            ->forOrganization($organizationId)
            ->where('asset_type', $zoneType)
            ->whereNotNull('risk_index')
            ->orderByDesc('risk_index')
            ->limit($limit);

        if (is_string($hazardKey) && $hazardKey !== '') {
            $query->where('hazard_key', $hazardKey);
        }

        return $query->get();
    }

    /**
     * Compare named scenario zone rows for an organization.
     *
     * @param  list<string>  $scenarioNames
     * @return Collection<int, ExposureSummary>
     */
    public function compareScenarios(int $organizationId, array $scenarioNames): Collection
    {
        $names = array_values(array_filter(array_map(
            fn ($n) => $this->normalizeScenarioName((string) $n),
            $scenarioNames,
        ), fn ($n) => $n !== ''));

        $zoneType = (string) config('disaster.exposure.zone_asset_type', 'zone');

        return ExposureSummary::query()
            ->forOrganization($organizationId)
            ->where('asset_type', $zoneType)
            ->whereIn('scenario_name', $names)
            ->orderByDesc('risk_index')
            ->get();
    }

    /**
     * @return int Asset rows written
     */
    protected function computeAssetsPostgis(
        int $organizationId,
        string $hazardKey,
        float $hazardLatitude,
        float $hazardLongitude,
        float $radiusM,
        string $scenarioName,
    ): int {
        $settings = $this->pluginSettings($organizationId);
        $layerId = $this->hazardLayerIdFromSettings($settings, $hazardKey);
        $now = now();
        $written = 0;

        $layer = $layerId !== null
            ? $this->layerCatalog->findForOrganization($organizationId, $layerId)
            : null;

        foreach ($this->assetTables() as $assetType => $table) {
            $quoted = $this->quoteIdent($table);

            if ($layer !== null) {
                $hazardTable = $this->quoteIdent($layer->table_name);
                $hazardGeom = $this->quoteIdent(GeometryColumnHelper::resolve($layer->table_name));
                $hazardSql = "(SELECT ST_UnaryUnion(ST_Collect({$hazardGeom})) FROM {$hazardTable})";
                $sql = "
                    SELECT a.id,
                        ST_Distance(a.geom::geography, ({$hazardSql})::geography) AS distance_m,
                        (
                            ST_DWithin(a.geom::geography, ({$hazardSql})::geography, ?)
                            OR ST_Intersects(a.geom, {$hazardSql})
                        ) AS in_hazard
                    FROM {$quoted} a
                    WHERE a.organization_id = ?
                      AND a.geom IS NOT NULL
                ";
                $bindings = [$radiusM, $organizationId];
            } else {
                $sql = "
                    SELECT a.id,
                        ST_Distance(
                            a.geom::geography,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                        ) AS distance_m,
                        ST_DWithin(
                            a.geom::geography,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                            ?
                        ) AS in_hazard
                    FROM {$quoted} a
                    WHERE a.organization_id = ?
                      AND a.geom IS NOT NULL
                ";
                $bindings = [
                    $hazardLongitude,
                    $hazardLatitude,
                    $hazardLongitude,
                    $hazardLatitude,
                    $radiusM,
                    $organizationId,
                ];
            }

            $rows = DB::select($sql, $bindings);
            foreach ($rows as $row) {
                ExposureSummary::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'hazard_key' => $hazardKey,
                        'scenario_name' => $scenarioName,
                        'asset_type' => $assetType,
                        'asset_id' => (int) $row->id,
                    ],
                    [
                        'distance_m' => round((float) $row->distance_m, 2),
                        'in_hazard' => (bool) $row->in_hazard,
                        'computed_at' => $now,
                        'hazard_zone_layer_id' => $layerId,
                        'meta' => [
                            'engine' => 'postgis',
                            'hazard_lat' => $hazardLatitude,
                            'hazard_lon' => $hazardLongitude,
                            'radius_m' => $radiusM,
                            'layer_id' => $layerId,
                        ],
                    ],
                );
                $written++;
            }
        }

        $this->computeZone(
            organizationId: $organizationId,
            hazardKey: $hazardKey,
            hazardZoneLayerId: $layerId,
            hazardLatitude: $hazardLatitude,
            hazardLongitude: $hazardLongitude,
            radiusM: $radiusM,
            scenarioName: $scenarioName,
            engineNote: 'asset_compute_zone_rollup',
        );

        return $written;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     population_exposed: int,
     *     road_length_m: float,
     *     building_count: int,
     *     medical_facility_count: int,
     *     critical_site_count: int,
     *     staging_area_count: int,
     *     assets_exposed: int,
     *     condition_grades: list<string>
     * }
     */
    protected function postgisZoneMetrics(
        int $organizationId,
        int $hazardLayerId,
        ?int $hazardFeatureId,
        array $settings,
    ): array {
        $layer = $this->layerCatalog->findForOrganization($organizationId, $hazardLayerId);
        if ($layer === null) {
            return $this->emptyMetrics();
        }

        $hazardExpr = $this->hazardGeometrySql($layer, $hazardFeatureId);
        $medical = $this->countPluginPointsIntersecting('dm_medical_facilities', $organizationId, $hazardExpr);
        $critical = $this->countPluginPointsIntersecting('dm_critical_sites', $organizationId, $hazardExpr);
        $staging = $this->countPluginPolygonsIntersecting('dm_staging_areas', $organizationId, $hazardExpr);
        $buildings = $this->countCoreLayerIntersecting($organizationId, $settings, 'buildings', $hazardExpr);
        $population = $this->sumPopulationIntersecting($organizationId, $settings, $hazardExpr);
        $roads = $this->sumRoadLengthIntersecting($organizationId, $settings, $hazardExpr);
        $grades = $this->conditionGradesInZone($organizationId, $hazardExpr);

        return [
            'population_exposed' => $population,
            'road_length_m' => $roads,
            'building_count' => $buildings,
            'medical_facility_count' => $medical,
            'critical_site_count' => $critical,
            'staging_area_count' => $staging,
            'assets_exposed' => $medical + $critical + $staging,
            'condition_grades' => $grades,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     population_exposed: int,
     *     road_length_m: float,
     *     building_count: int,
     *     medical_facility_count: int,
     *     critical_site_count: int,
     *     staging_area_count: int,
     *     assets_exposed: int,
     *     condition_grades: list<string>
     * }
     */
    protected function postgisPointBufferMetrics(
        int $organizationId,
        float $latitude,
        float $longitude,
        float $radiusM,
        array $settings,
    ): array {
        $buffer = 'ST_Buffer(ST_SetSRID(ST_MakePoint('.((float) $longitude).', '.((float) $latitude).'), 4326)::geography, '.((float) $radiusM).')::geometry';
        $medical = $this->countPluginPointsIntersecting('dm_medical_facilities', $organizationId, $buffer);
        $critical = $this->countPluginPointsIntersecting('dm_critical_sites', $organizationId, $buffer);
        $staging = $this->countPluginPolygonsIntersecting('dm_staging_areas', $organizationId, $buffer);
        $buildings = $this->countCoreLayerIntersecting($organizationId, $settings, 'buildings', $buffer);
        $population = $this->sumPopulationIntersecting($organizationId, $settings, $buffer);
        $roads = $this->sumRoadLengthIntersecting($organizationId, $settings, $buffer);
        $grades = $this->conditionGradesInZone($organizationId, $buffer);

        return [
            'population_exposed' => $population,
            'road_length_m' => $roads,
            'building_count' => $buildings,
            'medical_facility_count' => $medical,
            'critical_site_count' => $critical,
            'staging_area_count' => $staging,
            'assets_exposed' => $medical + $critical + $staging,
            'condition_grades' => $grades,
        ];
    }

    /**
     * @deprecated legacy SQLite path — approximate exposure without PostGIS.
     *
     * @param  array<string, mixed>  $settings
     * @return array{
     *     population_exposed: int,
     *     road_length_m: float,
     *     building_count: int,
     *     medical_facility_count: int,
     *     critical_site_count: int,
     *     staging_area_count: int,
     *     assets_exposed: int,
     *     condition_grades: list<string>
     * }
     */
    protected function legacyZoneMetrics(
        int $organizationId,
        string $hazardKey,
        ?float $hazardLatitude,
        ?float $hazardLongitude,
        float $radiusM,
        ?int $resolvedLayerId,
        ?int $hazardFeatureId,
        array $settings,
    ): array {
        $lat = $hazardLatitude;
        $lon = $hazardLongitude;

        if (($lat === null || $lon === null) && $resolvedLayerId !== null) {
            $centroid = $this->legacyLayerCentroid($organizationId, $resolvedLayerId, $hazardFeatureId);
            $lat = $centroid['latitude'] ?? $lat;
            $lon = $centroid['longitude'] ?? $lon;
        }

        $medical = 0;
        $critical = 0;
        $staging = 0;
        $grades = [];

        if ($lat !== null && $lon !== null) {
            $this->legacyProximity->computeLegacyHaversine(
                $organizationId,
                $hazardKey,
                $lat,
                $lon,
                $radiusM,
                '',
            );

            $medical = ExposureSummary::query()
                ->forOrganization($organizationId)
                ->where('hazard_key', $hazardKey)
                ->where('asset_type', 'medical_facility')
                ->where('in_hazard', true)
                ->count();
            $critical = ExposureSummary::query()
                ->forOrganization($organizationId)
                ->where('hazard_key', $hazardKey)
                ->where('asset_type', 'critical_site')
                ->where('in_hazard', true)
                ->count();

            $siteIds = ExposureSummary::query()
                ->forOrganization($organizationId)
                ->where('hazard_key', $hazardKey)
                ->where('asset_type', 'critical_site')
                ->where('in_hazard', true)
                ->pluck('asset_id');
            $grades = $this->latestConditionGradesForSites($organizationId, $siteIds->all());

            foreach (StagingArea::query()->forOrganization($organizationId)->whereNotNull('geom')->get(['id', 'geom']) as $area) {
                $coords = GeometryReader::pointCoordinates($area->geom)
                    ?? $this->legacyPolygonCentroid($area->geom);
                if ($coords === null) {
                    continue;
                }
                if (SpatialHelper::distance($lat, $lon, $coords['latitude'], $coords['longitude']) <= $radiusM) {
                    $staging++;
                }
            }
        }

        $buildings = 0;
        $population = 0;
        $roads = 0.0;
        if ($resolvedLayerId !== null && $lat !== null && $lon !== null) {
            $approx = $this->legacyCoreLayerApprox($organizationId, $settings, $lat, $lon, $radiusM);
            $buildings = $approx['buildings'];
            $population = $approx['population'];
            $roads = $approx['roads_m'];
        }

        return [
            'population_exposed' => $population,
            'road_length_m' => $roads,
            'building_count' => $buildings,
            'medical_facility_count' => $medical,
            'critical_site_count' => $critical,
            'staging_area_count' => $staging,
            'assets_exposed' => $medical + $critical + $staging,
            'condition_grades' => $grades,
        ];
    }

    protected function hazardGeometrySql(Layer $layer, ?int $featureId): string
    {
        $table = $this->quoteIdent($layer->table_name);
        $geom = $this->quoteIdent(GeometryColumnHelper::resolve($layer->table_name));

        if ($featureId !== null) {
            return '(SELECT '.$geom.' FROM '.$table.' WHERE id = '.(int) $featureId.')';
        }

        return '(SELECT ST_UnaryUnion(ST_Collect('.$geom.')) FROM '.$table.')';
    }

    protected function countPluginPointsIntersecting(string $table, int $organizationId, string $hazardExpr): int
    {
        $quoted = $this->quoteIdent($table);
        $row = DB::selectOne(
            "SELECT COUNT(*)::int AS c FROM {$quoted} a WHERE a.organization_id = ? AND a.geom IS NOT NULL AND ST_Intersects(a.geom, {$hazardExpr})",
            [$organizationId],
        );

        return (int) ($row->c ?? 0);
    }

    protected function countPluginPolygonsIntersecting(string $table, int $organizationId, string $hazardExpr): int
    {
        return $this->countPluginPointsIntersecting($table, $organizationId, $hazardExpr);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function countCoreLayerIntersecting(int $organizationId, array $settings, string $kind, string $hazardExpr): int
    {
        $layer = $this->resolveExposureLayer($organizationId, $settings, $kind);
        if ($layer === null) {
            return 0;
        }

        $table = $this->quoteIdent($layer->table_name);
        $geom = $this->quoteIdent(GeometryColumnHelper::resolve($layer->table_name));
        $row = DB::selectOne(
            "SELECT COUNT(*)::int AS c FROM {$table} f WHERE f.{$geom} IS NOT NULL AND ST_Intersects(f.{$geom}, {$hazardExpr})",
        );

        return (int) ($row->c ?? 0);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function sumPopulationIntersecting(int $organizationId, array $settings, string $hazardExpr): int
    {
        $layer = $this->resolveExposureLayer($organizationId, $settings, 'population');
        if ($layer === null) {
            return 0;
        }

        $attr = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($settings['population_attribute'] ?? config('disaster.exposure.population_attribute', 'population'))) ?: 'population';
        $table = $this->quoteIdent($layer->table_name);
        $geom = $this->quoteIdent(GeometryColumnHelper::resolve($layer->table_name));
        $quotedAttr = $this->quoteIdent($attr);

        try {
            $row = DB::selectOne(
                "SELECT COALESCE(SUM((f.{$quotedAttr})::numeric), 0) AS s
                 FROM {$table} f
                 WHERE f.{$geom} IS NOT NULL AND ST_Intersects(f.{$geom}, {$hazardExpr})",
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) round((float) ($row->s ?? 0));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function sumRoadLengthIntersecting(int $organizationId, array $settings, string $hazardExpr): float
    {
        $layer = $this->resolveExposureLayer($organizationId, $settings, 'roads');
        if ($layer === null) {
            return 0.0;
        }

        $table = $this->quoteIdent($layer->table_name);
        $geom = $this->quoteIdent(GeometryColumnHelper::resolve($layer->table_name));

        try {
            $row = DB::selectOne(
                "SELECT COALESCE(SUM(ST_Length(ST_Intersection(f.{$geom}, {$hazardExpr})::geography)), 0) AS s
                 FROM {$table} f
                 WHERE f.{$geom} IS NOT NULL AND ST_Intersects(f.{$geom}, {$hazardExpr})",
            );
        } catch (\Throwable) {
            return 0.0;
        }

        return (float) ($row->s ?? 0);
    }

    /**
     * @return list<string>
     */
    protected function conditionGradesInZone(int $organizationId, string $hazardExpr): array
    {
        $rows = DB::select(
            "SELECT c.id FROM dm_critical_sites c
             WHERE c.organization_id = ? AND c.geom IS NOT NULL AND ST_Intersects(c.geom, {$hazardExpr})",
            [$organizationId],
        );
        $ids = array_map(fn ($r) => (int) $r->id, $rows);

        return $this->latestConditionGradesForSites($organizationId, $ids);
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<string>
     */
    protected function latestConditionGradesForSites(int $organizationId, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $grades = [];
        foreach ($siteIds as $siteId) {
            $row = InfrastructureCondition::query()
                ->forOrganization($organizationId)
                ->where('site_id', $siteId)
                ->orderByDesc('last_inspection_at')
                ->orderByDesc('id')
                ->first();
            if ($row?->condition_grade !== null) {
                $grades[] = $row->condition_grade instanceof \BackedEnum
                    ? $row->condition_grade->value
                    : (string) $row->condition_grade;
            }
        }

        return $grades;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function resolveExposureLayer(int $organizationId, array $settings, string $kind): ?Layer
    {
        $keys = config('disaster.exposure.layer_setting_keys', []);
        $settingKey = is_array($keys) ? ($keys[$kind] ?? null) : null;
        if (! is_string($settingKey) || $settingKey === '') {
            return null;
        }
        $layerId = $settings[$settingKey] ?? null;
        if (! is_int($layerId) && ! (is_numeric($layerId) && (int) $layerId > 0)) {
            return null;
        }

        return $this->layerCatalog->findForOrganization($organizationId, (int) $layerId);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function hazardLayerIdFromSettings(array $settings, string $hazardKey): ?int
    {
        $layerKey = match ($hazardKey) {
            'floodplain', 'flood' => 'floodplain_layer_id',
            'fault', 'seismic' => 'fault_layer_id',
            'wildfire' => 'wildfire_layer_id',
            default => str_starts_with($hazardKey, 'dam_failure:') ? null : null,
        };

        if ($layerKey === null) {
            return null;
        }

        $layerId = $settings[$layerKey] ?? null;

        return is_numeric($layerId) && (int) $layerId > 0 ? (int) $layerId : null;
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    protected function legacyLayerCentroid(int $organizationId, int $layerId, ?int $featureId): ?array
    {
        $layer = $this->layerCatalog->findForOrganization($organizationId, $layerId);
        if ($layer === null) {
            return null;
        }

        $page = $this->layerCatalog->listFeatures($layer, $organizationId, 200);
        $features = $page['features'] ?? [];
        $items = is_object($features) && method_exists($features, 'items') ? $features->items() : (is_iterable($features) ? $features : []);

        foreach ($items as $feature) {
            $row = is_array($feature) ? $feature : (array) $feature;
            if ($featureId !== null && (int) ($row['id'] ?? 0) !== $featureId) {
                continue;
            }
            $geom = $row['geom'] ?? $row['geometry'] ?? null;
            if (is_string($geom)) {
                try {
                    [$lat, $lon] = SpatialHelper::centroid($geom);

                    return ['latitude' => $lat, 'longitude' => $lon];
                } catch (\Throwable) {
                    $point = GeometryReader::pointCoordinates($geom);
                    if ($point !== null) {
                        return $point;
                    }
                }
            }
            if ($featureId !== null) {
                break;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{buildings: int, population: int, roads_m: float}
     */
    protected function legacyCoreLayerApprox(
        int $organizationId,
        array $settings,
        float $lat,
        float $lon,
        float $radiusM,
    ): array {
        $buildings = 0;
        $population = 0;
        $roads = 0.0;
        $popAttr = (string) ($settings['population_attribute'] ?? config('disaster.exposure.population_attribute', 'population'));

        foreach (['buildings', 'population', 'roads'] as $kind) {
            $layer = $this->resolveExposureLayer($organizationId, $settings, $kind);
            if ($layer === null) {
                continue;
            }
            $page = $this->layerCatalog->listFeatures($layer, $organizationId, 500);
            $features = $page['features'] ?? [];
            $items = is_object($features) && method_exists($features, 'items') ? $features->items() : (is_iterable($features) ? $features : []);
            foreach ($items as $feature) {
                $row = is_array($feature) ? $feature : (array) $feature;
                $geom = $row['geom'] ?? $row['geometry'] ?? null;
                if (! is_string($geom)) {
                    continue;
                }
                $coords = GeometryReader::pointCoordinates($geom);
                if ($coords === null) {
                    try {
                        [$clat, $clon] = SpatialHelper::centroid($geom);
                        $coords = ['latitude' => $clat, 'longitude' => $clon];
                    } catch (\Throwable) {
                        continue;
                    }
                }
                if (SpatialHelper::distance($lat, $lon, $coords['latitude'], $coords['longitude']) > $radiusM) {
                    continue;
                }
                if ($kind === 'buildings') {
                    $buildings++;
                } elseif ($kind === 'population') {
                    $population += (int) ($row[$popAttr] ?? 0);
                } else {
                    $roads += 100.0;
                }
            }
        }

        return ['buildings' => $buildings, 'population' => $population, 'roads_m' => $roads];
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    protected function legacyPolygonCentroid(mixed $geom): ?array
    {
        if (! is_string($geom)) {
            return null;
        }
        try {
            [$lat, $lon] = SpatialHelper::centroid($geom);

            return ['latitude' => $lat, 'longitude' => $lon];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     population_exposed: int,
     *     road_length_m: float,
     *     building_count: int,
     *     medical_facility_count: int,
     *     critical_site_count: int,
     *     staging_area_count: int,
     *     assets_exposed: int,
     *     condition_grades: list<string>
     * }
     */
    protected function emptyMetrics(): array
    {
        return [
            'population_exposed' => 0,
            'road_length_m' => 0.0,
            'building_count' => 0,
            'medical_facility_count' => 0,
            'critical_site_count' => 0,
            'staging_area_count' => 0,
            'assets_exposed' => 0,
            'condition_grades' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function assetTables(): array
    {
        return [
            'medical_facility' => 'dm_medical_facilities',
            'critical_site' => 'dm_critical_sites',
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

    protected function normalizeScenarioName(string $scenarioName): string
    {
        return trim($scenarioName);
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }
}
