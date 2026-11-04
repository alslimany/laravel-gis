<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Jobs\RecomputeEvacRoutesJob;
use Lumina\Disaster\Models\EvacAssignment;
use Lumina\Disaster\Models\RoutingRun;
use Lumina\Disaster\Services\EvacuationRoutingService;

class EvacRoutingController extends Controller
{
    use RespondsToDisasterConsole;

    public function assignments(Request $request): JsonResponse
    {
        Gate::authorize('disaster.view');
        $organizationId = (int) $request->user()->organization_id;

        $query = EvacAssignment::query()
            ->forOrganization($organizationId)
            ->with(['shelterSite:id,site_id,name,capacity', 'corridor:id,name,distance_m,travel_time_s,source'])
            ->orderByDesc('id');

        if ($request->filled('order')) {
            $query->where('evacuation_order_id', (int) $request->integer('order'));
        }

        return response()->json(['data' => $query->limit(500)->get()]);
    }

    public function compute(Request $request, EvacuationRoutingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'evacuation_order_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_evacuation_orders', 'id')->where('organization_id', $organizationId),
            ],
            'sync' => ['sometimes', 'boolean'],
            'zones' => ['nullable', 'array'],
            'zones.*.zone_key' => ['required', 'string', 'max:120'],
            'zones.*.population' => ['required', 'integer', 'min:0'],
            'zones.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'zones.*.lon' => ['required', 'numeric', 'between:-180,180'],
            'zones.*.vulnerable' => ['sometimes', 'boolean'],
            'zones.*.priority' => ['sometimes', 'integer'],
        ]);

        $orderId = isset($data['evacuation_order_id']) ? (int) $data['evacuation_order_id'] : null;
        $zones = $data['zones'] ?? [];
        $sync = (bool) ($data['sync'] ?? true);

        if ($sync) {
            $run = $service->recompute(
                $organizationId,
                $orderId,
                $zones,
                (int) $request->user()->id,
            );

            if ($this->wantsJsonResponse($request)) {
                return response()->json(['data' => $run], 201);
            }

            return redirect()->route('disaster.planning.index')
                ->with('success', 'Evacuation routes recomputed.');
        }

        RecomputeEvacRoutesJob::dispatch(
            $organizationId,
            $orderId,
            $zones,
            (int) $request->user()->id,
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['queued' => true], 202);
        }

        return redirect()->route('disaster.planning.index')
            ->with('success', 'Evacuation route recompute queued.');
    }

    public function override(
        Request $request,
        EvacAssignment $evacAssignment,
        EvacuationRoutingService $service,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $evacAssignment);
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'shelter_site_id' => [
                'required',
                'integer',
                Rule::exists('dm_critical_sites', 'id')->where('organization_id', $organizationId),
            ],
            'population' => ['required', 'integer', 'min:0'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'corridor_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_evac_corridors', 'id')->where('organization_id', $organizationId),
            ],
        ]);

        $assignment = $service->overrideAssignment(
            $organizationId,
            $evacAssignment->zone_key,
            (int) $data['shelter_site_id'],
            (int) $data['population'],
            $data['override_reason'] ?? null,
            $evacAssignment->evacuation_order_id !== null ? (int) $evacAssignment->evacuation_order_id : null,
            (int) $request->user()->id,
            isset($data['corridor_id']) ? (int) $data['corridor_id'] : $evacAssignment->corridor_id,
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $assignment->fresh(['shelterSite', 'corridor'])]);
        }

        return redirect()->route('disaster.planning.index')
            ->with('success', 'Evacuation assignment overridden.');
    }

    public function runs(Request $request): JsonResponse
    {
        Gate::authorize('disaster.view');
        $organizationId = (int) $request->user()->organization_id;

        $runs = RoutingRun::query()
            ->forOrganization($organizationId)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $runs]);
    }

    protected function assertOrg(Request $request, EvacAssignment $assignment): void
    {
        abort_unless((int) $assignment->organization_id === (int) $request->user()->organization_id, 404);
    }
}
