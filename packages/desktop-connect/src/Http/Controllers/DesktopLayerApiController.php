<?php

namespace Lumina\DesktopConnect\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Layer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal desktop JSON surface for ability enforcement tests (DG-1.6).
 * Full OGC API Features arrives in DG-1.7+.
 */
class DesktopLayerApiController extends Controller
{
    public function show(Request $request, Layer $layer): JsonResponse
    {
        return response()->json([
            'data' => [
                'public_id' => $layer->public_id,
                'alias' => $layer->alias,
                'name' => $layer->name,
            ],
        ]);
    }
}
