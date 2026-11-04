<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;

/** Live AVL unit listing — requires telemetry or manage (not public). */
class AvlTelemetryController extends Controller
{
    public function page(Request $request): Response
    {
        abort_unless(Gate::any(['disaster.manage', 'disaster.telemetry']), 403);

        $organizationId = (int) $request->user()->organization_id;

        $units = AvlUnit::query()
            ->forOrganization($organizationId)
            ->orderByDesc('last_ping_at')
            ->get(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at', 'speed_kmh', 'heading_deg'])
            ->all();

        return Inertia::render('Disaster/Avl', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'units' => $units,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::any(['disaster.manage', 'disaster.telemetry']), 403);

        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => AvlUnit::query()
                ->forOrganization($organizationId)
                ->orderByDesc('last_ping_at')
                ->get(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at', 'speed_kmh', 'heading_deg']),
        ]);
    }
}
