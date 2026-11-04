<?php

namespace Lumina\Disaster\Services;

/**
 * risk_index = hazard_level × exposure_score × vulnerability_score.
 * Weights and normalization live in config/disaster.php; org plugin settings may override.
 */
class RiskIndexCalculator
{
    /**
     * @param  array{
     *     population?: int|float|null,
     *     buildings?: int|float|null,
     *     roads_m?: int|float|null,
     *     assets?: int|float|null
     * }  $metrics
     * @param  array<string, mixed>  $weightsOverride  Merged over config risk.weights
     * @return array{
     *     hazard_level: float,
     *     exposure_score: float,
     *     vulnerability_score: float,
     *     risk_index: float,
     *     weights_snapshot: array<string, mixed>
     * }
     */
    public function calculate(
        float $hazardLevel,
        array $metrics,
        float $vulnerability,
        array $weightsOverride = [],
    ): array {
        $weights = $this->mergeWeights($weightsOverride);
        $norm = $weights['normalization'] ?? [];
        $expW = $weights['exposure'] ?? [];

        $population = max(0.0, (float) ($metrics['population'] ?? 0));
        $buildings = max(0.0, (float) ($metrics['buildings'] ?? 0));
        $roads = max(0.0, (float) ($metrics['roads_m'] ?? 0));
        $assets = max(0.0, (float) ($metrics['assets'] ?? 0));

        $pNorm = $this->normalize($population, (float) ($norm['population'] ?? 10000));
        $bNorm = $this->normalize($buildings, (float) ($norm['buildings'] ?? 500));
        $rNorm = $this->normalize($roads, (float) ($norm['roads_m'] ?? 50000));
        $aNorm = $this->normalize($assets, (float) ($norm['assets'] ?? 20));

        $wPop = (float) ($expW['population'] ?? 0.40);
        $wBld = (float) ($expW['buildings'] ?? 0.25);
        $wRoad = (float) ($expW['roads'] ?? 0.15);
        $wAst = (float) ($expW['assets'] ?? 0.20);
        $sum = max(0.0001, $wPop + $wBld + $wRoad + $wAst);

        $exposure = ($wPop / $sum) * $pNorm
            + ($wBld / $sum) * $bNorm
            + ($wRoad / $sum) * $rNorm
            + ($wAst / $sum) * $aNorm;

        $hazard = max(0.0, min(1.0, $hazardLevel));
        $vuln = max(0.0, min(1.0, $vulnerability));
        $exposure = max(0.0, min(1.0, $exposure));
        $risk = round($hazard * $exposure * $vuln, 4);

        $snapshot = [
            'formula' => 'hazard_level × exposure_score × vulnerability_score',
            'weights' => $weights,
            'normalized' => [
                'population' => round($pNorm, 4),
                'buildings' => round($bNorm, 4),
                'roads' => round($rNorm, 4),
                'assets' => round($aNorm, 4),
            ],
            'raw' => [
                'population' => $population,
                'buildings' => $buildings,
                'roads_m' => $roads,
                'assets' => $assets,
            ],
        ];

        return [
            'hazard_level' => round($hazard, 4),
            'exposure_score' => round($exposure, 4),
            'vulnerability_score' => round($vuln, 4),
            'risk_index' => $risk,
            'weights_snapshot' => $snapshot,
        ];
    }

    /**
     * Map infrastructure condition grades to a vulnerability score (0–1).
     *
     * @param  list<string>  $grades
     */
    public function vulnerabilityFromGrades(array $grades, array $weightsOverride = []): float
    {
        $weights = $this->mergeWeights($weightsOverride);
        $map = $weights['vulnerability']['condition_grades'] ?? [];
        $default = (float) ($weights['vulnerability']['default'] ?? 0.5);

        if ($grades === []) {
            return $default;
        }

        $sum = 0.0;
        foreach ($grades as $grade) {
            $sum += (float) ($map[$grade] ?? $default);
        }

        return max(0.0, min(1.0, $sum / count($grades)));
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public function mergeWeights(array $override = []): array
    {
        $base = config('disaster.risk.weights', []);

        return array_replace_recursive(is_array($base) ? $base : [], $override);
    }

    protected function normalize(float $value, float $scale): float
    {
        if ($scale <= 0) {
            return 0.0;
        }

        return min(1.0, $value / $scale);
    }
}
