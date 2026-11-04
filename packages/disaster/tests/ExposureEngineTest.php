<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\ExposureSummary;
use Lumina\Disaster\Models\InfrastructureCondition;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Services\ExposureComputer;
use Lumina\Disaster\Services\RiskIndexCalculator;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class ExposureEngineTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_risk_index_matches_hand_checked_fixture(): void
    {
        $calc = new RiskIndexCalculator;
        $weights = [
            'exposure' => [
                'population' => 0.40,
                'buildings' => 0.25,
                'roads' => 0.15,
                'assets' => 0.20,
            ],
            'normalization' => [
                'population' => 10000,
                'buildings' => 500,
                'roads_m' => 50000,
                'assets' => 20,
            ],
            'vulnerability' => [
                'default' => 0.5,
                'condition_grades' => [
                    'good' => 0.2,
                    'fair' => 0.4,
                    'poor' => 0.7,
                    'critical' => 1.0,
                ],
            ],
        ];

        // Hand-checked:
        // pop 1000/10000=0.1, bld 50/500=0.1, roads 5000/50000=0.1, assets 4/20=0.2
        // exposure = 0.4*0.1 + 0.25*0.1 + 0.15*0.1 + 0.2*0.2 = 0.04+0.025+0.015+0.04 = 0.12
        // hazard=0.8, vuln=0.7 (poor) → risk = 0.8 * 0.12 * 0.7 = 0.0672
        $result = $calc->calculate(0.8, [
            'population' => 1000,
            'buildings' => 50,
            'roads_m' => 5000,
            'assets' => 4,
        ], 0.7, $weights);

        $this->assertSame(0.8, $result['hazard_level']);
        $this->assertSame(0.12, $result['exposure_score']);
        $this->assertSame(0.7, $result['vulnerability_score']);
        $this->assertSame(0.0672, $result['risk_index']);
    }

    public function test_zone_compute_writes_risk_index_on_sqlite_legacy_path(): void
    {
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'NEAR',
            'name' => 'Near hospital',
            'geom' => GeometryWriter::pointFromCoordinates(32.8800, 13.1900),
        ]);
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'FAR',
            'name' => 'Far hospital',
            'geom' => GeometryWriter::pointFromCoordinates(33.5000, 13.5000),
        ]);

        $site = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'DAM-1',
            'name' => 'Test Dam',
            'type' => CriticalSiteType::Dam->value,
            'geom' => GeometryWriter::pointFromCoordinates(32.8802, 13.1902),
            'downstream_zone_layer_id' => null,
        ]);
        InfrastructureCondition::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => $site->id,
            'condition_grade' => InfrastructureConditionGrade::Poor->value,
            'last_inspection_at' => now(),
        ]);

        /** @var ExposureComputer $computer */
        $computer = $this->app->make(ExposureComputer::class);
        $this->assertFalse($computer->usesPostgis(), 'Package tests run on SQLite without PostGIS');

        $summary = $computer->computeZone(
            organizationId: $this->disasterOrg->id,
            hazardKey: 'test-flood',
            hazardLatitude: 32.8801,
            hazardLongitude: 13.1901,
            radiusM: 2000,
            hazardLevel: 0.8,
            scenarioName: '',
        );

        $this->assertSame('zone', $summary->asset_type);
        $this->assertSame(0.8, (float) $summary->hazard_level);
        $this->assertNotNull($summary->risk_index);
        $this->assertGreaterThanOrEqual(1, (int) $summary->medical_facility_count);
        $this->assertGreaterThanOrEqual(1, (int) $summary->assets_exposed);
        $this->assertTrue((bool) ($summary->meta['deprecated_fallback'] ?? false));
        $this->assertSame('legacy_haversine_sqlite', $summary->meta['engine'] ?? null);

        $near = ExposureSummary::query()
            ->forOrganization($this->disasterOrg->id)
            ->where('hazard_key', 'test-flood')
            ->where('asset_type', 'medical_facility')
            ->where('in_hazard', true)
            ->count();
        $this->assertGreaterThanOrEqual(1, $near);
    }

    public function test_dam_failure_scenario_requires_downstream_layer(): void
    {
        $site = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'DAM-2',
            'name' => 'No Downstream',
            'type' => CriticalSiteType::Dam->value,
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
            'downstream_zone_layer_id' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ExposureComputer::class)->computeDamFailureScenario(
            $this->disasterOrg->id,
            $site->id,
            'what-if-fails',
        );
    }

    public function test_exposure_report_command_ranks_scenarios(): void
    {
        $computer = $this->app->make(ExposureComputer::class);
        $computer->computeZone(
            organizationId: $this->disasterOrg->id,
            hazardKey: 'floodplain',
            hazardLatitude: 32.88,
            hazardLongitude: 13.19,
            radiusM: 5000,
            hazardLevel: 0.5,
            scenarioName: 'baseline',
        );
        $computer->computeZone(
            organizationId: $this->disasterOrg->id,
            hazardKey: 'floodplain',
            hazardLatitude: 32.88,
            hazardLongitude: 13.19,
            radiusM: 5000,
            hazardLevel: 1.0,
            scenarioName: 'worst-case',
        );

        $exit = Artisan::call('disaster:exposure-report', [
            'organization_id' => $this->disasterOrg->id,
            '--compare' => 'baseline,worst-case',
        ]);
        $this->assertSame(0, $exit);

        $compared = $computer->compareScenarios($this->disasterOrg->id, ['baseline', 'worst-case']);
        $this->assertCount(2, $compared);

        $baseline = $compared->firstWhere('scenario_name', 'baseline');
        $worst = $compared->firstWhere('scenario_name', 'worst-case');
        $this->assertNotNull($baseline);
        $this->assertNotNull($worst);
        // Higher hazard_level ⇒ higher or equal risk_index for identical exposure.
        $this->assertGreaterThanOrEqual((float) $worst->risk_index, (float) $baseline->risk_index);
    }

    public function test_exposure_summaries_have_risk_index_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('dm_exposure_summaries', 'risk_index'));
        $this->assertTrue(Schema::hasColumn('dm_exposure_summaries', 'population_exposed'));
        $this->assertTrue(Schema::hasColumn('dm_exposure_summaries', 'scenario_name'));
        $this->assertTrue(Schema::hasColumn('dm_exposure_summaries', 'weights_snapshot'));
    }
}
