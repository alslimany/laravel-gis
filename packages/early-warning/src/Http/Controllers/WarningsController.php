<?php

namespace Lumina\EarlyWarning\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\ExposureSummary;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

/**
 * Warnings (#62 / UI-1): basins + hourly risk/precip timeline.
 *
 * JSON for API/map-builder callers (Accept: application/json or ?format=json);
 * otherwise the Arabic-first Inertia page EarlyWarning/Warnings with filters.
 */
class WarningsController extends Controller
{
    private const LEVEL_RANK = ['red' => 4, 'orange' => 3, 'yellow' => 2, 'green' => 1];

    public const HOUR_OPTIONS = [6, 12, 24, 48, 72];

    public function __invoke(Request $request): JsonResponse|InertiaResponse
    {
        $payload = $this->payload($request);

        if ($request->query('format') === 'json' || ($request->wantsJson() && ! $request->header('X-Inertia'))) {
            return response()->json($payload);
        }

        $basins = collect($payload['basins'])->map(function (array $basin) {
            $peak = collect($basin['timeline'])->sortByDesc(fn ($row) => [self::LEVEL_RANK[$row['risk_level']] ?? 0, $row['score'] ?? 0])->first();

            return [
                ...$basin,
                'display_name' => app()->getLocale() === 'ar' && $basin['name_ar'] ? $basin['name_ar'] : $basin['name'],
                'peak_level' => $peak['risk_level'] ?? 'green',
                'peak_at' => $peak['forecast_at'] ?? null,
                'precip_total_mm' => round((float) collect($basin['timeline'])->sum(fn ($row) => (float) ($row['precip_mm'] ?? 0)), 1),
            ];
        })->values();

        return Inertia::render('EarlyWarning/Warnings', [
            'advisory' => $payload['advisory'],
            'authority' => $payload['authority'],
            'generated_at' => $payload['generated_at'],
            'hours' => $payload['hours'],
            'basins' => $basins,
            'filters' => [
                'hours' => count($payload['hours']),
                'level' => in_array($request->query('level'), ['red', 'orange', 'yellow', 'green'], true) ? $request->query('level') : null,
                'q' => (string) $request->query('q', ''),
            ],
            'hour_options' => self::HOUR_OPTIONS,
            'levels' => ['red', 'orange', 'yellow', 'green'],
            'index_url' => route('early-warning.index'),
            'warnings_url' => route('early-warning.warnings'),
            'json_url' => route('early-warning.warnings', ['format' => 'json']),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
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

        // Live Open-Meteo rows plus stored exercise forecasts (e.g. the NCMCD demo) owned by this org.
        // Stored rows carry props.organization_id so one org's exercise never shows for another.
        // When both exist for the same hour, the live Open-Meteo value wins.
        $storedSources = (array) config('early-warning.warnings.stored_forecast_sources', ['ncmcd-demo']);
        $forecasts = Forecast::query()
            ->whereIn('source', array_values(array_unique(['open-meteo', ...$storedSources])))
            ->whereIn('basin_code', $basins->pluck('basin_code'))
            ->whereBetween('forecast_at', [$now, $until])
            ->get(['basin_code', 'source', 'forecast_at', 'precipitation_mm', 'props'])
            ->filter(fn (Forecast $f) => $f->source === 'open-meteo'
                || (int) ($f->props['organization_id'] ?? 0) === $organizationId)
            ->sortBy(fn (Forecast $f) => $f->source === 'open-meteo' ? 1 : 0)
            ->values();

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

        return [
            'plugin' => 'early-warning',
            ...AdvisoryCopy::forUi(),
            'generated_at' => now()->utc()->toIso8601String(),
            'hours' => $hours,
            'basins' => $basinPayload->all(),
        ];
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
