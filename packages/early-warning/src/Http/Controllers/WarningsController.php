<?php

namespace Lumina\EarlyWarning\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\ExposureSummary;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

/**
 * Map-builder warnings feed (#62): basins + hourly risk/precip timeline.
 */
class WarningsController extends Controller
{
    private const LEVEL_RANK = ['red' => 4, 'orange' => 3, 'yellow' => 2, 'green' => 1];

    public function __invoke(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $horizon = max(1, min(168, (int) $request->integer('hours', config('early-warning.risk.horizon_hours', 72))));
        $now = now()->utc()->startOfHour();

        $codes = Subscription::query()
            ->where('organization_id', $organizationId)
            ->pluck('basin_code');

        if ($codes->isEmpty()) {
            $codes = Basin::query()->orderBy('basin_code')->pluck('basin_code');
        }

        $basins = Basin::query()
            ->whereIn('basin_code', $codes)
            ->orderBy('basin_code')
            ->get(['basin_code', 'name', 'name_ar', 'area_km2']);

        $until = $now->copy()->addHours($horizon - 1);

        $risks = RiskAssessment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('basin_code', $basins->pluck('basin_code'))
            ->whereBetween('forecast_at', [$now, $until])
            ->orderBy('forecast_at')
            ->get(['basin_code', 'forecast_at', 'risk_level', 'score', 'inputs']);

        $forecasts = Forecast::query()
            ->where('source', 'open-meteo')
            ->whereIn('basin_code', $basins->pluck('basin_code'))
            ->whereBetween('forecast_at', [$now, $until])
            ->get(['basin_code', 'forecast_at', 'precipitation_mm']);

        $hours = [];
        for ($i = 0; $i < $horizon; $i++) {
            $hours[] = $now->copy()->addHours($i)->toIso8601String();
        }

        $riskByBasin = $risks->groupBy('basin_code');
        $precipByBasin = $forecasts->groupBy('basin_code');
        $exposure = ExposureSummary::query()
            ->whereIn('basin_code', $basins->pluck('basin_code'))
            ->get()
            ->keyBy('basin_code');

        $basinPayload = $basins->map(function (Basin $basin) use ($hours, $riskByBasin, $precipByBasin, $exposure) {
            $riskRows = $riskByBasin->get($basin->basin_code, collect());
            $precipRows = $precipByBasin->get($basin->basin_code, collect());
            $exp = $exposure->get($basin->basin_code);

            $riskAt = $this->indexByHour($riskRows);
            $precipAt = $this->indexByHour($precipRows);

            $timeline = [];
            foreach ($hours as $iso) {
                $key = Carbon::parse($iso)->utc()->format('Y-m-d H:00:00');
                /** @var RiskAssessment|null $risk */
                $risk = $riskAt->get($key);
                /** @var Forecast|null $fc */
                $fc = $precipAt->get($key);
                $timeline[] = [
                    'forecast_at' => $iso,
                    'risk_level' => $risk?->risk_level ?? 'green',
                    'score' => $risk?->score !== null ? (float) $risk->score : null,
                    'precip_mm' => $fc?->precipitation_mm !== null ? (float) $fc->precipitation_mm : null,
                ];
            }

            $current = $timeline[0] ?? [
                'forecast_at' => $hours[0] ?? null,
                'risk_level' => 'green',
                'score' => null,
                'precip_mm' => null,
            ];

            return [
                'basin_code' => $basin->basin_code,
                'name' => $basin->name,
                'name_ar' => $basin->name_ar,
                'area_km2' => $basin->area_km2 !== null ? (float) $basin->area_km2 : null,
                'risk_level' => $current['risk_level'],
                'score' => $current['score'],
                'precip_mm' => $current['precip_mm'],
                'buildings' => $exp?->building_count,
                'road_km' => $exp?->road_km !== null ? (float) $exp->road_km : null,
                'timeline' => $timeline,
            ];
        })->sort(function (array $a, array $b) {
            $ra = self::LEVEL_RANK[$a['risk_level']] ?? 0;
            $rb = self::LEVEL_RANK[$b['risk_level']] ?? 0;
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        })->values();

        return response()->json([
            'plugin' => 'early-warning',
            ...AdvisoryCopy::forUi(),
            'generated_at' => now()->utc()->toIso8601String(),
            'hours' => $hours,
            'basins' => $basinPayload,
        ]);
    }

    /**
     * @param  Collection<int, RiskAssessment|Forecast>  $rows
     * @return Collection<string, RiskAssessment|Forecast>
     */
    protected function indexByHour(Collection $rows): Collection
    {
        return $rows->keyBy(fn ($row) => $row->forecast_at->utc()->format('Y-m-d H:00:00'));
    }
}
