<?php

namespace Lumina\DesktopConnect\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Health/scaffold endpoints for Desktop GIS Connect.
 * Grants UI lives on GrantController (DG-1.5).
 */
class DesktopConnectController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['plugin' => 'desktop-connect']);
    }

    public function ping(): JsonResponse
    {
        return response()->json(['pong' => true]);
    }
}
