<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Support\GeometryWriter;

class EvacCorridorController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => EvacCorridor::query()->forOrganization($organizationId)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request, $organizationId);
        $geojson = $this->lineFromRequest($data);
        unset($data['geojson_text']);

        $corridor = EvacCorridor::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'geom' => $geojson !== null ? GeometryWriter::lineFromGeoJson($geojson) : null,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $corridor], 201);
        }

        return redirect()->route('disaster.planning.index')->with('success', 'Evacuation corridor created.');
    }

    public function update(Request $request, EvacCorridor $evacCorridor): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $evacCorridor);
        $data = $this->validated($request, (int) $request->user()->organization_id, updating: true);
        $geojson = $this->lineFromRequest($data);
        unset($data['geojson_text']);
        $evacCorridor->fill($data);
        if ($geojson !== null) {
            $evacCorridor->geom = GeometryWriter::lineFromGeoJson($geojson);
        }
        $evacCorridor->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $evacCorridor]);
        }

        return redirect()->route('disaster.planning.index')->with('success', 'Evacuation corridor updated.');
    }

    public function destroy(Request $request, EvacCorridor $evacCorridor): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $evacCorridor);
        $evacCorridor->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.planning.index')->with('success', 'Evacuation corridor deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, int $organizationId, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'shelter_site_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_critical_sites', 'id')->where('organization_id', $organizationId),
            ],
            'props' => ['nullable', 'array'],
            'geojson' => ['nullable', 'array'],
            'geojson_text' => ['nullable', 'string', 'max:50000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function lineFromRequest(array $data): ?array
    {
        if (isset($data['geojson']) && is_array($data['geojson'])) {
            return $data['geojson'];
        }

        $text = trim((string) ($data['geojson_text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded) || ($decoded['type'] ?? '') !== 'LineString') {
            abort(422, 'geojson_text must be a GeoJSON LineString.');
        }

        return $decoded;
    }

    protected function assertOrg(Request $request, EvacCorridor $corridor): void
    {
        abort_unless((int) $corridor->organization_id === (int) $request->user()->organization_id, 404);
    }
}
