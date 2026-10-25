<?php

namespace Lumina\EarlyWarning\Tests;

use Lumina\EarlyWarning\Hydrology\RiskCalculator;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

/** #61: risk score bands from rain / soil / terrain. */
class RiskCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(EarlyWarningServiceProvider::class);
    }

    public function test_dry_conditions_are_green(): void
    {
        $result = (new RiskCalculator)->assess([
            'precip_1h' => 0,
            'precip_6h' => 0,
            'precip_24h' => 1,
            'antecedent_72h' => 0,
            'mean_slope_deg' => 2,
            'area_km2' => 50,
        ]);

        $this->assertSame('green', $result['risk_level']);
        $this->assertLessThan(25, $result['score']);
    }

    public function test_heavy_six_hour_rain_reaches_red(): void
    {
        $result = (new RiskCalculator)->assess([
            'precip_1h' => 20,
            'precip_6h' => 70,
            'precip_24h' => 90,
            'antecedent_72h' => 100,
            'mean_slope_deg' => 12,
            'area_km2' => 50,
        ]);

        $this->assertSame('red', $result['risk_level']);
        $this->assertGreaterThanOrEqual(75, $result['score']);
        $this->assertArrayHasKey('advisory', $result['inputs']);
    }

    public function test_moderate_rain_with_wet_soil_is_at_least_yellow(): void
    {
        $result = (new RiskCalculator)->assess([
            'precip_1h' => 3,
            'precip_6h' => 18,
            'precip_24h' => 30,
            'antecedent_72h' => 60,
            'mean_slope_deg' => 8,
            'area_km2' => 200,
        ]);

        $this->assertContains($result['risk_level'], ['yellow', 'orange', 'red']);
        $this->assertGreaterThanOrEqual(25, $result['score']);
    }

    public function test_explicit_soil_saturation_overrides_antecedent_proxy(): void
    {
        $drySoil = (new RiskCalculator)->assess([
            'precip_1h' => 2,
            'precip_6h' => 10,
            'precip_24h' => 15,
            'antecedent_72h' => 80,
            'soil_saturation' => 0.1,
            'mean_slope_deg' => 5,
            'area_km2' => 100,
        ]);

        $wetSoil = (new RiskCalculator)->assess([
            'precip_1h' => 2,
            'precip_6h' => 10,
            'precip_24h' => 15,
            'antecedent_72h' => 80,
            'soil_saturation' => 0.95,
            'mean_slope_deg' => 5,
            'area_km2' => 100,
        ]);

        $this->assertSame(0.1, $drySoil['inputs']['soil_saturation']);
        $this->assertSame(0.95, $wetSoil['inputs']['soil_saturation']);
        $this->assertGreaterThan($drySoil['score'], $wetSoil['score']);
    }
}
