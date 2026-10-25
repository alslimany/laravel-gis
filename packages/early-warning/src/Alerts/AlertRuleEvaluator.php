<?php

namespace Lumina\EarlyWarning\Alerts;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

/**
 * Evaluates org AlertRules against current forecasts / risk assessments.
 * Writes AlertLog rows (channel=log for now; Telegram/email land in the channels task).
 */
class AlertRuleEvaluator
{
    private const LEVEL_RANK = ['green' => 1, 'yellow' => 2, 'orange' => 3, 'red' => 4];

    /**
     * @return int number of new alert log rows
     */
    public function evaluateOrganization(int $organizationId): int
    {
        $rules = AlertRule::query()
            ->where('organization_id', $organizationId)
            ->where('enabled', true)
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $codes = Subscription::query()
            ->where('organization_id', $organizationId)
            ->pluck('basin_code');

        if ($codes->isEmpty()) {
            return 0;
        }

        $horizon = max(1, (int) config('early-warning.alerts.eval_horizon_hours', 24));
        $now = now()->utc()->startOfHour();
        $until = $now->copy()->addHours($horizon - 1);

        $forecasts = Forecast::query()
            ->where('source', 'open-meteo')
            ->whereIn('basin_code', $codes)
            ->whereBetween('forecast_at', [$now->copy()->subHours(23), $until])
            ->orderBy('forecast_at')
            ->get(['basin_code', 'forecast_at', 'precipitation_mm']);

        $risks = RiskAssessment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('basin_code', $codes)
            ->whereBetween('forecast_at', [$now, $until])
            ->get(['id', 'basin_code', 'forecast_at', 'risk_level', 'score']);

        $logged = 0;
        foreach ($rules as $rule) {
            $targetCodes = $rule->basin_code
                ? $codes->filter(fn ($c) => $c === $rule->basin_code)
                : $codes;

            foreach ($targetCodes as $basinCode) {
                $logged += $this->evaluateRuleForBasin(
                    $rule,
                    $basinCode,
                    $now,
                    $horizon,
                    $forecasts->where('basin_code', $basinCode),
                    $risks->where('basin_code', $basinCode),
                );
            }
        }

        return $logged;
    }

    /**
     * @param  Collection<int, Forecast>  $forecasts
     * @param  Collection<int, RiskAssessment>  $risks
     */
    protected function evaluateRuleForBasin(
        AlertRule $rule,
        string $basinCode,
        Carbon $now,
        int $horizon,
        Collection $forecasts,
        Collection $risks,
    ): int {
        if ($this->alreadyLogged($rule, $basinCode)) {
            return 0;
        }

        $byHour = [];
        foreach ($forecasts as $row) {
            $byHour[$row->forecast_at->utc()->format('Y-m-d H:00:00')] = (float) ($row->precipitation_mm ?? 0);
        }

        for ($h = 0; $h < $horizon; $h++) {
            $at = $now->copy()->addHours($h);
            $key = $at->format('Y-m-d H:00:00');
            /** @var RiskAssessment|null $risk */
            $risk = $risks->first(fn (RiskAssessment $r) => $r->forecast_at->utc()->format('Y-m-d H:00:00') === $key);

            $value = $this->metricValue($rule->metric, $byHour, $at, $risk);
            if (! $this->ruleTriggered($rule, $value, $risk)) {
                continue;
            }

            return $this->writeLogs($rule, $basinCode, $at, $value ?? 0.0, $risk);
        }

        return 0;
    }

    protected function ruleTriggered(AlertRule $rule, ?float $value, ?RiskAssessment $risk): bool
    {
        $levelHit = $this->riskLevelHit($rule->risk_level_min, $risk?->risk_level);

        if ($value === null) {
            // e.g. risk_score with no assessment yet — allow level-only match.
            return $levelHit;
        }

        if (! $this->compare($value, $rule->operator, (float) $rule->threshold)) {
            return false;
        }

        // Metric hit: if risk_level_min is set and a risk row exists, also require the level gate.
        if ($rule->risk_level_min !== null && $risk !== null) {
            return $levelHit;
        }

        return true;
    }

    /**
     * @param  array<string, float>  $byHour
     */
    protected function metricValue(string $metric, array $byHour, Carbon $at, ?RiskAssessment $risk): ?float
    {
        return match ($metric) {
            'precipitation_mm', 'precip_1h_mm' => $byHour[$at->format('Y-m-d H:00:00')] ?? 0.0,
            'precip_6h_mm' => $this->sumWindow($byHour, $at, 6),
            'precip_24h_mm' => $this->sumWindow($byHour, $at, 24),
            'risk_score' => $risk?->score !== null ? (float) $risk->score : null,
            default => null,
        };
    }

    /**
     * @param  array<string, float>  $byHour
     */
    protected function sumWindow(array $byHour, Carbon $endInclusive, int $hours): float
    {
        $sum = 0.0;
        for ($i = 0; $i < $hours; $i++) {
            $sum += $byHour[$endInclusive->copy()->subHours($i)->format('Y-m-d H:00:00')] ?? 0.0;
        }

        return round($sum, 3);
    }

    protected function compare(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '==', '=' => abs($value - $threshold) < 0.0001,
            default => $value >= $threshold,
        };
    }

    protected function riskLevelHit(?string $min, ?string $current): bool
    {
        if ($min === null || $current === null) {
            return false;
        }

        return (self::LEVEL_RANK[$current] ?? 0) >= (self::LEVEL_RANK[$min] ?? 99);
    }

    protected function alreadyLogged(AlertRule $rule, string $basinCode): bool
    {
        $dedupe = max(1, (int) config('early-warning.alerts.dedupe_hours', 6));

        return AlertLog::query()
            ->where('alert_rule_id', $rule->id)
            ->where('basin_code', $basinCode)
            ->where('created_at', '>=', now()->subHours($dedupe))
            ->exists();
    }

    protected function writeLogs(
        AlertRule $rule,
        string $basinCode,
        Carbon $at,
        float $value,
        ?RiskAssessment $risk,
    ): int {
        $channels = is_array($rule->channels) && $rule->channels !== []
            ? $rule->channels
            : ['log'];

        $created = 0;
        foreach ($channels as $channel) {
            AlertLog::query()->create([
                'organization_id' => $rule->organization_id,
                'alert_rule_id' => $rule->id,
                'risk_assessment_id' => $risk?->id,
                'basin_code' => $basinCode,
                'channel' => (string) $channel,
                'status' => 'triggered',
                'payload' => [
                    'advisory' => AdvisoryCopy::message(),
                    'authority' => AdvisoryCopy::authority(),
                    'metric' => $rule->metric,
                    'operator' => $rule->operator,
                    'threshold' => $rule->threshold,
                    'value' => $value,
                    'risk_level' => $risk?->risk_level,
                    'score' => $risk?->score,
                    'forecast_at' => $at->toIso8601String(),
                    'rule_name' => $rule->name,
                ],
                'sent_at' => null,
            ]);
            $created++;
        }

        return $created;
    }
}
