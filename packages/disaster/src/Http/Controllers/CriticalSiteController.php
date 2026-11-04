<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\GeometryWriter;

class CriticalSiteController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => CriticalSite::query()->forOrganization($organizationId)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $site = CriticalSite::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'geom' => isset($data['latitude'], $data['longitude'])
                ? GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude'])
                : null,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $site], 201);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Critical site created.');
    }

    public function update(Request $request, CriticalSite $criticalSite): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $criticalSite);
        $data = $this->validated($request, updating: true);
        $criticalSite->fill($data);
        if (isset($data['latitude'], $data['longitude'])) {
            $criticalSite->geom = GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude']);
        }
        $criticalSite->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $criticalSite]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Critical site updated.');
    }

    public function destroy(Request $request, CriticalSite $criticalSite): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $criticalSite);
        $criticalSite->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Critical site deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'site_id' => [$updating ? 'sometimes' : 'required', 'string', 'max:64'],
            'type' => [$updating ? 'sometimes' : 'required', Rule::enum(CriticalSiteType::class)],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'remaining_capacity' => ['nullable', 'integer', 'min:0'],
            'capacity_mcm' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
            'year_built' => ['nullable', 'integer', 'min:1000', 'max:3000'],
            'spillway_capacity' => ['nullable', 'numeric', 'min:0'],
            'downstream_zone_layer_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    protected function assertOrg(Request $request, CriticalSite $site): void
    {
        abort_unless((int) $site->organization_id === (int) $request->user()->organization_id, 404);
    }
}
