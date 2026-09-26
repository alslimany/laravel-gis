<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Models\Map;
use App\Services\FeatureService;
use Illuminate\Http\Request;

class MvtController extends Controller
{
    public function __construct(protected FeatureService $features)
    {
        $this->middleware('auth')->only('tile');
        $this->middleware('organization')->only('tile');
    }

    /**
     * Serve Mapbox Vector Tile for a layer.
     */
    public function tile(Request $request, Layer $layer, int $z, int $x, int $y)
    {
        $this->authorize('view', $layer);

        return $this->renderTile($request, $layer, $z, $x, $y);
    }

    /**
     * Tiles for a layer that is actually drawn on a public shared map.
     * The share token is not a key to every layer in the organization.
     */
    public function publicTile(Request $request, string $token, Layer $layer, int $z, int $x, int $y)
    {
        $map = Map::query()
            ->where('share_token', $token)
            ->where('is_public', true)
            ->first();

        $listed = $map && collect($map->layers ?? [])->contains(
            fn ($entry) => is_array($entry) && (int) ($entry['id'] ?? 0) === (int) $layer->id
        );

        if (! $map || ! $listed || (int) $map->organization_id !== (int) $layer->organization_id) {
            abort(404);
        }

        return $this->renderTile($request, $layer, $z, $x, $y);
    }

    protected function renderTile(Request $request, Layer $layer, int $z, int $x, int $y)
    {
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
