<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Jobs\RecomputeEvacRoutesJob;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\GeometryWriter;

class ClosureController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $closures = Closure::query()
            ->forOrganization($organizationId)
            ->orderByDesc('updated_at')
            ->get(['id', 'external_id', 'closure_type', 'severity', 'valid_from', 'valid_to', 'source']);

        if ($this->wantsJsonResponse($request) && ! $request->inertia()) {
            return response()->json(['data' => $closures]);
        }

        return Inertia::render('Disaster/Closures', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'closures' => $closures,
            'store_url' => route('disaster.closures.store'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $geojson = $this->lineGeoJsonFromRequest($data);
        unset($data['geojson_text']);

        $closure = Closure::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'source' => 'console',
            'geom' => $geojson !== null ? GeometryWriter::lineFromGeoJson($geojson) : null,
        ]);

        RecomputeEvacRoutesJob::dispatch($organizationId);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $closure], 201);
        }

        return redirect()->route('disaster.closures.index')->with('success', 'Closure created.');
    }

    public function destroy(Request $request, Closure $closure): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $closure);
        $organizationId = (int) $closure->organization_id;
        $closure->delete();

        RecomputeEvacRoutesJob::dispatch($organizationId);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.closures.index')->with('success', 'Closure deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'external_id' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'closure_type' => ['nullable', 'string', 'max:64'],
            'severity' => ['nullable', 'string', 'max:32'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'geojson_text' => ['nullable', 'string', 'max:50000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function lineGeoJsonFromRequest(array $data): ?array
    {
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

    protected function assertOrg(Request $request, Closure $closure): void
    {
        abort_unless((int) $closure->organization_id === (int) $request->user()->organization_id, 404);
    }
}
