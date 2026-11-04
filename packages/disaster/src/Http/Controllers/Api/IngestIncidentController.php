<?php

namespace Lumina\Disaster\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Support\GeometryWriter;

class IngestIncidentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('disaster.ingest');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:120'],
            'incident_type' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:Point'],
            'geometry.coordinates' => ['required', 'array', 'size:2'],
            'geometry.coordinates.*' => ['numeric'],
        ]);

        $coords = $data['geometry']['coordinates'];
        $longitude = (float) $coords[0];
        $latitude = (float) $coords[1];

        $incident = Incident::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'external_id' => $data['external_id'],
            ],
            [
                'source' => 'ingest',
                'incident_type' => $data['incident_type'] ?? null,
                'priority' => $data['priority'] ?? 3,
                'status' => $data['status'] ?? 'open',
                'opened_at' => now(),
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'geom' => GeometryWriter::pointFromCoordinates($latitude, $longitude),
            ],
        );

        return response()->json(['data' => $incident], 201);
    }
}
