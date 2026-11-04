<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentImagery;

class IncidentImageryController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('disaster.view');
        $this->assertOrg($request, $incident);

        return response()->json([
            'data' => IncidentImagery::query()
                ->forOrganization((int) $request->user()->organization_id)
                ->where('incident_id', $incident->id)
                ->orderBy('role')
                ->get(['id', 'incident_id', 'role', 'layer_id', 'label', 'created_at', 'updated_at']),
        ]);
    }

    public function store(Request $request, Incident $incident): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $incident);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(['pre', 'post'])],
            'layer_id' => ['required', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $row = IncidentImagery::query()->updateOrCreate(
            [
                'organization_id' => (int) $request->user()->organization_id,
                'incident_id' => $incident->id,
                'role' => $data['role'],
            ],
            [
                'layer_id' => $data['layer_id'],
                'label' => $data['label'] ?? null,
            ],
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $row], 201);
        }

        return redirect()->route('disaster.damage.index')->with('success', 'Incident imagery registered.');
    }

    public function destroy(Request $request, Incident $incident, IncidentImagery $imagery): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $incident);
        abort_unless(
            (int) $imagery->organization_id === (int) $request->user()->organization_id
            && (int) $imagery->incident_id === (int) $incident->id,
            404,
        );

        $imagery->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.damage.index')->with('success', 'Incident imagery removed.');
    }

    protected function assertOrg(Request $request, Incident $incident): void
    {
        abort_unless((int) $incident->organization_id === (int) $request->user()->organization_id, 404);
    }
}
