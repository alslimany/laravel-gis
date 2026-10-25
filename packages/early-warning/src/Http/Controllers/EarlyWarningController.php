<?php

namespace Lumina\EarlyWarning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\ExposureSummary;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Models\Subscription;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

class EarlyWarningController extends Controller
{
    /**
     * Console module home. Inertia for the browser; JSON when explicitly requested
     * (and not an Inertia visit).
     */
    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $latestFetch = Forecast::query()->max('fetched_at');

        $summary = [
            'plugin' => 'early-warning',
            ...AdvisoryCopy::forUi(),
            'basin_count' => Basin::query()->count(),
            'forecast_rows' => Forecast::query()->count(),
            'latest_fetch_at' => $latestFetch,
            'subscriptions' => Subscription::query()
                ->where('organization_id', $organizationId)
                ->count(),
        ];

        // Keep a JSON probe for tooling; Inertia visits must not hit this branch.
        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json([
                ...$summary,
                // Back-compat with the Phase 1 JSON shape.
                'basins' => $summary['basin_count'],
            ]);
        }

        $codes = Subscription::query()
            ->where('organization_id', $organizationId)
            ->pluck('basin_code');

        if ($codes->isEmpty()) {
            $codes = Basin::query()->orderBy('basin_code')->pluck('basin_code');
        }

        $now = now()->utc();
        $current = RiskAssessment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('basin_code', $codes)
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->get()
            ->keyBy('basin_code');

        $exposure = ExposureSummary::query()
            ->whereIn('basin_code', $codes)
            ->get()
            ->keyBy('basin_code');

        $basins = Basin::query()
            ->whereIn('basin_code', $codes)
            ->orderBy('basin_code')
            ->get(['basin_code', 'name', 'name_ar', 'area_km2'])
            ->map(function (Basin $basin) use ($current, $exposure) {
                $risk = $current->get($basin->basin_code);
                $exp = $exposure->get($basin->basin_code);

                return [
                    'basin_code' => $basin->basin_code,
                    'name' => $basin->name,
                    'name_ar' => $basin->name_ar,
                    'area_km2' => $basin->area_km2 !== null ? (float) $basin->area_km2 : null,
                    'risk_level' => $risk?->risk_level ?? 'green',
                    'score' => $risk?->score !== null ? (float) $risk->score : null,
                    'forecast_at' => $risk?->forecast_at?->toIso8601String(),
                    'buildings' => $exp?->building_count,
                    'road_km' => $exp?->road_km !== null ? (float) $exp->road_km : null,
                ];
            })
            ->sortByDesc(fn (array $b) => match ($b['risk_level']) {
                'red' => 4,
                'orange' => 3,
                'yellow' => 2,
                default => 1,
            })
            ->values()
            ->all();

        return Inertia::render('EarlyWarning/Index', [
            ...$summary,
            'basins' => $basins,
            'warningsUrl' => route('early-warning.warnings'),
            'mapBuilderUrl' => route('maps.builder'),
            'alertRulesUrl' => route('early-warning.alert-rules.index'),
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'basins' => Basin::query()->count(),
            'forecasts' => Forecast::query()->count(),
            'open_meteo_url' => config('early-warning.open_meteo.base_url'),
        ]);
    }
}
