<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Services\FeatureService;
use Illuminate\Http\Request;

class MvtController extends Controller
{
    public function __construct(protected FeatureService $features)
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Serve Mapbox Vector Tile for a layer.
     */
    public function tile(Request $request, Layer $layer, int $z, int $x, int $y)
    {
        $this->authorize('view', $layer);

        $timeField = $request->query('time_field') ?? $request->query('timeField');
        $timeFrom = $request->query('time_from') ?? $request->query('timeFrom');
        $timeTo = $request->query('time_to') ?? $request->query('timeTo');

        try {
            $mvt = $this->features->tile($layer, $z, $x, $y, $timeField, $timeFrom, $timeTo);

            return response($mvt, 200, [
                'Content-Type' => 'application/vnd.mapbox-vector-tile',
                'Cache-Control' => 'public, max-age=300',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
