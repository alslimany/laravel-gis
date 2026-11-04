<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\GeometryWriter;

class StagingAreaController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => StagingArea::query()->forOrganization($organizationId)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);
        $geojson = $this->resolveGeoJson($data);
        unset($data['latitude'], $data['longitude']);

        $area = StagingArea::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'geom' => $geojson !== null ? GeometryWriter::polygonFromGeoJson($geojson) : null,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $area], 201);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Staging area created.');
    }

    public function update(Request $request, StagingArea $stagingArea): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $stagingArea);
        $data = $this->validated($request, updating: true);
        $geojson = $this->resolveGeoJson($data);
        unset($data['latitude'], $data['longitude']);
        $stagingArea->fill($data);
        if ($geojson !== null) {
            $stagingArea->geom = GeometryWriter::polygonFromGeoJson($geojson);
        }
        $stagingArea->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $stagingArea]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Staging area updated.');
    }

    public function destroy(Request $request, StagingArea $stagingArea): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $stagingArea);
        $stagingArea->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Staging area deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'staging_id' => [$updating ? 'sometimes' : 'required', 'string', 'max:64'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'max_vehicle_cap' => ['nullable', 'integer', 'min:0'],
            'fuel_availability' => ['nullable', 'boolean'],
            'manning_agency' => ['nullable', 'string', 'max:120'],
            'resource_status' => ['nullable', Rule::enum(ResourceStatus::class)],
            'geojson' => ['nullable', 'array'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function resolveGeoJson(array $data): ?array
    {
        if (isset($data['geojson']) && is_array($data['geojson'])) {
            return $data['geojson'];
        }

        if (! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        $lat = (float) $data['latitude'];
        $lon = (float) $data['longitude'];
        $delta = 0.002;

        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$lon - $delta, $lat - $delta],
                [$lon + $delta, $lat - $delta],
                [$lon + $delta, $lat + $delta],
                [$lon - $delta, $lat + $delta],
                [$lon - $delta, $lat - $delta],
            ]],
        ];
    }

    protected function assertOrg(Request $request, StagingArea $area): void
    {
        abort_unless((int) $area->organization_id === (int) $request->user()->organization_id, 404);
    }
}
