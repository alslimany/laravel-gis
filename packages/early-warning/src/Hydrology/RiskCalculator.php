<?php

namespace Lumina\EarlyWarning\Hydrology;

use Lumina\EarlyWarning\Support\AdvisoryCopy;

/**
 * Flood-risk score from forecast rain, soil wetness (observed SMAP-class or antecedent
 * proxy), and basin terrain (#61 / Phase 2 observations).
 * Advisory only — decision support, not an official warning.
 */
class RiskCalculator
{
    /**
     * @param  array{
     *     precip_1h: float,
     *     precip_6h: float,
     *     precip_24h: float,
     *     antecedent_72h: float,
     *     mean_slope_deg?: float|null,
     *     area_km2?: float|null,
     *     soil_saturation?: float|null
     * }  $input
     * @return array{risk_level: string, score: float, inputs: array<string, mixed>}
     */
    public function assess(array $input): array
    {
        $precip1h = max(0.0, (float) ($input['precip_1h'] ?? 0));
        $precip6h = max(0.0, (float) ($input['precip_6h'] ?? 0));
        $precip24h = max(0.0, (float) ($input['precip_24h'] ?? 0));
        $antecedent = max(0.0, (float) ($input['antecedent_72h'] ?? 0));
        $slope = isset($input['mean_slope_deg']) ? (float) $input['mean_slope_deg'] : null;
        $area = isset($input['area_km2']) ? (float) $input['area_km2'] : null;

        $soil = $input['soil_saturation'] ?? null;
        if ($soil === null) {
            $ref = (float) config('early-warning.risk.antecedent_sat_mm', 80);
            $soil = $ref > 0 ? min(1.0, $antecedent / $ref) : 0.5;
        } else {
            $soil = max(0.0, min(1.0, (float) $soil));
        }

        $rainScore = $this->rainScore($precip1h, $precip6h, $precip24h);
        $terrain = $this->terrainFactor($slope, $area);

        $wRain = (float) config('early-warning.risk.weights.rain', 0.70);
        $wSoil = (float) config('early-warning.risk.weights.soil', 0.20);
        $wTerrain = (float) config('early-warning.risk.weights.terrain', 0.10);
        $sum = max(0.0001, $wRain + $wSoil + $wTerrain);

        $score = ($wRain / $sum) * $rainScore
            + ($wSoil / $sum) * ($soil * 100.0)
            + ($wTerrain / $sum) * ($terrain * 100.0);
        $score = round(min(100.0, max(0.0, $score)), 3);

        $level = $this->levelFromScore($score);

        return [
            'risk_level' => $level,
            'score' => $score,
            'inputs' => [
                'precip_1h' => $precip1h,
                'precip_6h' => $precip6h,
                'precip_24h' => $precip24h,
                'antecedent_72h' => $antecedent,
                'soil_saturation' => round($soil, 4),
                'terrain_factor' => round($terrain, 4),
                'mean_slope_deg' => $slope,
                'area_km2' => $area,
                'rain_score' => round($rainScore, 3),
                'advisory' => AdvisoryCopy::shortTag(),
            ],
        ];
    }

    protected function rainScore(float $p1, float $p6, float $p24): float
    {
        $t = config('early-warning.risk.rain_mm', []);

        return max(
            $this->piecewise($p1, (float) ($t['1h']['yellow'] ?? 5), (float) ($t['1h']['orange'] ?? 15), (float) ($t['1h']['red'] ?? 30)),
            $this->piecewise($p6, (float) ($t['6h']['yellow'] ?? 15), (float) ($t['6h']['orange'] ?? 35), (float) ($t['6h']['red'] ?? 60)),
            $this->piecewise($p24, (float) ($t['24h']['yellow'] ?? 25), (float) ($t['24h']['orange'] ?? 50), (float) ($t['24h']['red'] ?? 80)),
        );
    }

    /** Map mm onto 0–100 with yellow/orange/red anchors at 40 / 65 / 85. */
    protected function piecewise(float $mm, float $yellow, float $orange, float $red): float
    {
        if ($mm <= 0) {
            return 0.0;
        }
        if ($mm < $yellow) {
            return 40.0 * ($mm / max(0.001, $yellow));
        }
        if ($mm < $orange) {
            return 40.0 + 25.0 * (($mm - $yellow) / max(0.001, $orange - $yellow));
        }
        if ($mm < $red) {
            return 65.0 + 20.0 * (($mm - $orange) / max(0.001, $red - $orange));
        }

        return min(100.0, 85.0 + 15.0 * min(1.0, ($mm - $red) / max(0.001, $red)));
    }

    protected function terrainFactor(?float $slopeDeg, ?float $areaKm2): float
    {
        $slopePart = $slopeDeg === null ? 0.4 : min(1.0, max(0.0, $slopeDeg) / 30.0);
        // Small, steep catchments respond faster.
        $sizePart = 0.5;
        if ($areaKm2 !== null && $areaKm2 > 0) {
            $sizePart = $areaKm2 < 100 ? 0.7 : ($areaKm2 < 1000 ? 0.5 : 0.35);
        }

        return min(1.0, 0.65 * $slopePart + 0.35 * $sizePart);
    }

    protected function levelFromScore(float $score): string
    {
        $bands = config('early-warning.risk.score_bands', [
            'yellow' => 25,
            'orange' => 50,
            'red' => 75,
        ]);

        if ($score >= (float) ($bands['red'] ?? 75)) {
            return 'red';
        }
        if ($score >= (float) ($bands['orange'] ?? 50)) {
            return 'orange';
        }
        if ($score >= (float) ($bands['yellow'] ?? 25)) {
            return 'yellow';
        }

        return 'green';
    }
}
