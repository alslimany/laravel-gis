<?php

namespace Lumina\EarlyWarning\Jobs;

use App\Models\OrganizationPlugin;
use App\Plugins\Contracts\GlobalJob;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Hydrology\RiskCalculator;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\Observation;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Throwable;

/**
 * Org-scoped assessments from global forecasts (#61, ADR D12).
 * Marker is GlobalJob: iterates enabled orgs; writes only ew_risk_assessments / subscriptions.
 */
class ComputeRiskJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(RiskCalculator $calculator, PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('early-warning')) {
            Log::info('early-warning:compute-risk skipped (plugin not installed)');

            return;
        }

        $orgIds = $plugins->enabledOrganizations('early-warning');
        if ($orgIds->isEmpty()) {
            Log::info('early-warning:compute-risk: no enabled organizations');

            return;
        }

        // Exercise/demo orgs (early-warning settings.exercise = true, e.g. the NCMCD seeder) keep
        // their stored risk; recomputing from live weather would overwrite the scenario.
        $exerciseOrgIds = $this->exerciseOrganizationIds($orgIds);
        if ($exerciseOrgIds !== []) {
            Log::info('early-warning:compute-risk: skipping exercise organizations', ['organizations' => $exerciseOrgIds]);
            $orgIds = $orgIds->reject(fn ($id) => in_array((int) $id, $exerciseOrgIds, true))->values();
            if ($orgIds->isEmpty()) {
                return;
            }
        }

        $basins = Basin::query()->orderBy('basin_code')->get()->keyBy('basin_code');
        if ($basins->isEmpty()) {
            Log::warning('early-warning:compute-risk: no basins');

            return;
        }

        $horizon = max(1, (int) config('early-warning.risk.horizon_hours', 72));
        $now = now()->utc();
        $upserted = 0;

        foreach ($orgIds as $organizationId) {
            $codes = $this->basinCodesForOrganization((int) $organizationId, $basins);
            foreach ($codes as $code) {
                /** @var Basin|null $basin */
                $basin = $basins->get($code);
                if (! $basin) {
                    continue;
                }
                $upserted += $this->assessBasin(
                    $calculator,
                    (int) $organizationId,
                    $basin,
                    $horizon,
                    $now,
                );
            }
        }

        Log::info('early-warning:compute-risk done', [
            'organizations' => $orgIds->count(),
            'rows' => $upserted,
        ]);
    }

    /**
     * @param  Collection<int, int|string>  $orgIds
     * @return list<int>
     */
    protected function exerciseOrganizationIds(Collection $orgIds): array
    {
        return OrganizationPlugin::query()
            ->where('plugin_key', 'early-warning')
            ->whereIn('organization_id', $orgIds->all())
            ->get()
            ->filter(fn (OrganizationPlugin $row) => (bool) (($row->settings ?? [])['exercise'] ?? false))
            ->map(fn (OrganizationPlugin $row) => (int) $row->organization_id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, Basin>  $basins
     * @return list<string>
     */
    protected function basinCodesForOrganization(int $organizationId, Collection $basins): array
    {
        $subscribed = Subscription::query()
            ->where('organization_id', $organizationId)
            ->pluck('basin_code')
            ->all();

        if ($subscribed !== []) {
            return $subscribed;
        }

        // Pilot convenience: subscribe every global basin the first time risk runs.
        $now = now();
        foreach ($basins->keys() as $code) {
            Subscription::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'basin_code' => $code],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        return $basins->keys()->values()->all();
    }

    protected function assessBasin(
        RiskCalculator $calculator,
        int $organizationId,
        Basin $basin,
        int $horizon,
        Carbon $now,
    ): int {
        $from = $now->copy()->subHours(72);
        $to = $now->copy()->addHours($horizon);

        $forecasts = Forecast::query()
            ->where('basin_code', $basin->basin_code)
            ->where('source', 'open-meteo')
            ->whereBetween('forecast_at', [$from, $to])
            ->orderBy('forecast_at')
            ->get(['forecast_at', 'precipitation_mm']);

        if ($forecasts->isEmpty()) {
            return 0;
        }

        /** @var array<string, float> $byHour precip keyed by Y-m-d H:00:00 UTC */
        $byHour = [];
        foreach ($forecasts as $row) {
            $key = $row->forecast_at->utc()->format('Y-m-d H:00:00');
            $byHour[$key] = (float) ($row->precipitation_mm ?? 0);
        }

        $futureKeys = collect(array_keys($byHour))
            ->filter(fn (string $k) => Carbon::parse($k, 'UTC')->gte($now->copy()->startOfHour()))
            ->sort()
            ->take($horizon)
            ->values();

        $rows = [];
        $ts = $now->toDateTimeString();

        $obsSource = (string) config('early-warning.observations.source', 'nasa-power');
        $useObs = (bool) config('early-warning.observations.enabled', true);

        foreach ($futureKeys as $hourKey) {
            $at = Carbon::parse($hourKey, 'UTC');
            $forecastAntecedent = $this->sumWindow($byHour, $at->copy()->subHour(), 72);
            $soil = null;
            $antecedent = $forecastAntecedent;
            $obsHours = 0;

            if ($useObs) {
                $soil = Observation::soilSaturationNear($basin->basin_code, $at, $obsSource);
                $obsWindow = Observation::precipWindow($basin->basin_code, $at->copy()->subHour(), 72, $obsSource);
                $obsHours = $obsWindow['hours'];
                // Prefer satellite/reanalysis antecedent when we have enough coverage.
                if ($obsHours >= 24) {
                    $antecedent = max($forecastAntecedent, $obsWindow['sum']);
                }
            }

            $result = $calculator->assess([
                'precip_1h' => $this->sumWindow($byHour, $at, 1),
                'precip_6h' => $this->sumWindow($byHour, $at, 6),
                'precip_24h' => $this->sumWindow($byHour, $at, 24),
                'antecedent_72h' => $antecedent,
                'soil_saturation' => $soil,
                'mean_slope_deg' => $basin->mean_slope_deg !== null ? (float) $basin->mean_slope_deg : null,
                'area_km2' => $basin->area_km2 !== null ? (float) $basin->area_km2 : null,
            ]);
            $result['inputs']['observation_source'] = $useObs ? $obsSource : null;
            $result['inputs']['observation_antecedent_hours'] = $obsHours;
            $result['inputs']['forecast_antecedent_72h'] = $forecastAntecedent;

            $rows[] = [
                'organization_id' => $organizationId,
                'basin_code' => $basin->basin_code,
                'forecast_at' => $at->toDateTimeString(),
                'valid_from' => $at->toDateTimeString(),
                'valid_until' => $at->copy()->addHour()->toDateTimeString(),
                'risk_level' => $result['risk_level'],
                'score' => $result['score'],
                'inputs' => json_encode($result['inputs'], JSON_THROW_ON_ERROR),
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            RiskAssessment::query()->upsert(
                $chunk,
                ['organization_id', 'basin_code', 'forecast_at'],
                ['valid_from', 'valid_until', 'risk_level', 'score', 'inputs', 'updated_at'],
            );
        }

        return count($rows);
    }

    /**
     * @param  array<string, float>  $byHour
     */
    protected function sumWindow(array $byHour, Carbon $endInclusive, int $hours): float
    {
        $sum = 0.0;
        for ($i = 0; $i < $hours; $i++) {
            $key = $endInclusive->copy()->subHours($i)->format('Y-m-d H:00:00');
            $sum += $byHour[$key] ?? 0.0;
        }

        return round($sum, 3);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('early-warning:compute-risk failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
