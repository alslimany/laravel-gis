<?php

namespace Lumina\Disaster\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Models\AvlPing;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Support\Enums\ResourceClass;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\GeometryWriter;

class IngestAvlPingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('disaster.ingest');

        $organizationId = (int) $request->user()->organization_id;
        $maxSkew = (int) config('disaster.max_skew_seconds', 300);

        $data = $request->validate([
            'unit_id' => ['required', 'string', 'max:64'],
            'resource_class' => ['required', Rule::enum(ResourceClass::class)],
            'resource_status' => ['nullable', Rule::enum(ResourceStatus::class)],
            'recorded_at' => ['nullable', 'date'],
            'speed_kmh' => ['nullable', 'numeric', 'min:0'],
            'heading_deg' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'extras' => ['nullable', 'array'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:Point'],
            'geometry.coordinates' => ['required', 'array', 'size:2'],
            'geometry.coordinates.*' => ['numeric'],
        ]);

        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();
        $skew = abs($recordedAt->diffInSeconds(now(), false));
        if ($skew > $maxSkew) {
            return response()->json(['message' => 'recorded_at exceeds avl_max_skew_seconds'], 422);
        }

        $coords = $data['geometry']['coordinates'];
        $longitude = (float) $coords[0];
        $latitude = (float) $coords[1];
        $geom = GeometryWriter::pointFromCoordinates($latitude, $longitude);

        AvlUnit::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'unit_id' => $data['unit_id'],
            ],
            [
                'resource_class' => $data['resource_class'],
                'resource_status' => $data['resource_status'] ?? ResourceStatus::Available->value,
                'last_ping_at' => $recordedAt,
                'speed_kmh' => $data['speed_kmh'] ?? null,
                'heading_deg' => $data['heading_deg'] ?? null,
                'extras' => $data['extras'] ?? null,
                'geom' => $geom,
            ],
        );

        AvlPing::query()->create([
            'organization_id' => $organizationId,
            'unit_id' => $data['unit_id'],
            'recorded_at' => $recordedAt,
            'extras' => $data['extras'] ?? null,
            'geom' => $geom,
        ]);

        return response()->json(['ok' => true], 201);
    }
}
