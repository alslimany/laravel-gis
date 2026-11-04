<?php

namespace Lumina\Disaster\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Lumina\Disaster\Jobs\RecomputeEvacRoutesJob;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Support\GeometryWriter;

class IngestClosureController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('disaster.ingest');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:120'],
            'closure_type' => ['nullable', 'string', 'max:64'],
            'severity' => ['nullable', 'string', 'max:32'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:LineString'],
            'geometry.coordinates' => ['required', 'array', 'min:2'],
        ]);

        $closure = Closure::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'external_id' => $data['external_id'],
            ],
            [
                'closure_type' => $data['closure_type'] ?? null,
                'severity' => $data['severity'] ?? null,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_to' => $data['valid_to'] ?? null,
                'source' => 'ingest',
                'geom' => GeometryWriter::lineFromGeoJson($data['geometry']),
            ],
        );

        RecomputeEvacRoutesJob::dispatch($organizationId);

        return response()->json(['data' => $closure], 201);
    }
}
