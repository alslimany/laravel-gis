<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Jobs\ComputeExposureJob;
use Lumina\Disaster\Jobs\ComputeProximityJob;
use Lumina\Disaster\Models\ExposureSummary;
use Lumina\Disaster\Services\ExposureComputer;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;

class ExposureController extends Controller
{
    use RespondsToDisasterConsole;

    public function page(Request $request, ExposureComputer $computer): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $summaries = ExposureSummary::query()
            ->forOrganization($organizationId)
            ->orderByDesc('computed_at')
            ->limit(200)
            ->get([
                'id',
                'hazard_key',
                'scenario_name',
                'asset_type',
                'asset_id',
                'distance_m',
                'in_hazard',
                'population_exposed',
                'road_length_m',
                'building_count',
                'assets_exposed',
                'risk_index',
                'exposure_score',
                'vulnerability_score',
                'hazard_level',
                'computed_at',
                'meta',
            ])
            ->all();

        $ranked = $computer->ranked($organizationId, null, 25)
            ->map(fn (ExposureSummary $row) => [
                'id' => $row->id,
                'hazard_key' => $row->hazard_key,
                'scenario_name' => $row->scenario_name,
                'risk_index' => $row->risk_index,
                'population_exposed' => $row->population_exposed,
                'assets_exposed' => $row->assets_exposed,
                'road_length_m' => $row->road_length_m,
            ])
            ->all();

        return Inertia::render('Disaster/Exposure', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'summaries' => $summaries,
            'ranked' => $ranked,
            'compute_url' => route('disaster.exposure.compute'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $hazardKey = $request->query('hazard_key');
        $scenario = $request->query('scenario_name');

        $query = ExposureSummary::query()->forOrganization($organizationId)->orderByDesc('computed_at');
        if (is_string($hazardKey) && $hazardKey !== '') {
            $query->where('hazard_key', $hazardKey);
        }
        if (is_string($scenario) && $scenario !== '') {
            $query->where('scenario_name', $scenario);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function compute(Request $request, ExposureComputer $computer): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'hazard_key' => ['required', 'string', 'max:64'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'numeric', 'min:1', 'max:500000'],
            'hazard_zone_layer_id' => ['nullable', 'integer', 'min:1'],
            'hazard_feature_id' => ['nullable', 'integer', 'min:1'],
            'dam_site_id' => ['nullable', 'integer', 'min:1'],
            'scenario_name' => ['nullable', 'string', 'max:128'],
            'hazard_level' => ['nullable', 'numeric', 'between:0,1'],
            'mode' => ['nullable', Rule::in(['assets', 'zone', 'scenario'])],
            'sync' => ['nullable', 'boolean'],
        ]);

        $mode = $data['mode'] ?? (
            isset($data['dam_site_id']) ? 'scenario'
                : (isset($data['hazard_zone_layer_id']) && ! isset($data['latitude']) ? 'zone' : 'assets')
        );
        $sync = $request->boolean('sync', ! $this->wantsJsonResponse($request));
        $scenarioName = (string) ($data['scenario_name'] ?? '');

        if ($mode === 'scenario' || $mode === 'zone') {
            if ($sync) {
                if (isset($data['dam_site_id'])) {
                    $summary = $computer->computeDamFailureScenario(
                        $organizationId,
                        (int) $data['dam_site_id'],
                        $scenarioName !== '' ? $scenarioName : 'dam-failure',
                        isset($data['hazard_level']) ? (float) $data['hazard_level'] : null,
                    );
                } else {
                    $summary = $computer->computeZone(
                        organizationId: $organizationId,
                        hazardKey: $data['hazard_key'],
                        hazardZoneLayerId: isset($data['hazard_zone_layer_id']) ? (int) $data['hazard_zone_layer_id'] : null,
                        hazardFeatureId: isset($data['hazard_feature_id']) ? (int) $data['hazard_feature_id'] : null,
                        hazardLatitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
                        hazardLongitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
                        radiusM: isset($data['radius_m']) ? (float) $data['radius_m'] : null,
                        hazardLevel: isset($data['hazard_level']) ? (float) $data['hazard_level'] : null,
                        scenarioName: $scenarioName,
                    );
                }

                if ($this->wantsJsonResponse($request)) {
                    return response()->json([
                        'queued' => false,
                        'summary' => $summary,
                        'risk_index' => $summary->risk_index,
                    ]);
                }

                return redirect()
                    ->route('disaster.exposure.page')
                    ->with('success', 'Zone exposure computed (risk_index '.$summary->risk_index.').');
            }

            ComputeExposureJob::dispatch(
                $organizationId,
                $data['hazard_key'],
                isset($data['hazard_zone_layer_id']) ? (int) $data['hazard_zone_layer_id'] : null,
                isset($data['hazard_feature_id']) ? (int) $data['hazard_feature_id'] : null,
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
                isset($data['radius_m']) ? (float) $data['radius_m'] : null,
                isset($data['hazard_level']) ? (float) $data['hazard_level'] : null,
                $scenarioName,
                isset($data['dam_site_id']) ? (int) $data['dam_site_id'] : null,
            );

            if ($this->wantsJsonResponse($request)) {
                return response()->json(['queued' => true]);
            }

            return redirect()->route('disaster.exposure.page')->with('success', 'Zone exposure compute queued.');
        }

        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($sync) {
            $written = $computer->compute(
                $organizationId,
                $data['hazard_key'],
                (float) $data['latitude'],
                (float) $data['longitude'],
                isset($data['radius_m']) ? (float) $data['radius_m'] : null,
                $scenarioName,
            );

            if ($this->wantsJsonResponse($request)) {
                return response()->json(['queued' => false, 'written' => $written]);
            }

            return redirect()
                ->route('disaster.exposure.page')
                ->with('success', "Exposure compute finished ({$written} summaries).");
        }

        ComputeProximityJob::dispatch(
            $organizationId,
            $data['hazard_key'],
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['radius_m']) ? (float) $data['radius_m'] : null,
            $scenarioName,
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['queued' => true]);
        }

        return redirect()->route('disaster.exposure.page')->with('success', 'Exposure compute queued.');
    }
}
