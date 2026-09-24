<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Layer;
use App\Services\ContentAccessService;
use App\Services\FeatureService;
use Illuminate\Http\Request;

class LayerApiController extends Controller
{
    public function __construct(
        protected FeatureService $features,
        protected ContentAccessService $access
    ) {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $layers = Layer::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 25));

        $layers->setCollection(
            $layers->getCollection()->filter(
                fn (Layer $layer) => $this->access->canView(
                    $user,
                    'layer',
                    $layer->id,
                    $layer->organization_id
                )
            )->values()
        );

        return response()->json($layers);
    }

    public function show(Request $request, Layer $layer)
    {
        $this->authorizeLayerView($request, $layer);

        return response()->json(['data' => $layer]);
    }

    public function geojson(Request $request, Layer $layer)
    {
        $this->authorizeLayerView($request, $layer);

        $geojson = $this->features->toGeoJson(
            $layer,
            $request->query('time')
        );

        return response()->json($geojson);
    }

    protected function authorizeLayerView(Request $request, Layer $layer): void
    {
        $user = $request->user();

        if (! $this->access->canView($user, 'layer', $layer->id, $layer->organization_id)) {
            abort(403, 'You do not have access to this layer.');
        }
    }
}
