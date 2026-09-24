<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Layer;
use App\Services\ContentAccessService;
use App\Services\FeatureService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\Request;

class FeatureApiController extends Controller
{
    public function __construct(
        protected FeatureService $features,
        protected ContentAccessService $access,
        protected WebhookDispatcher $webhooks
    ) {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request, Layer $layer)
    {
        $this->authorizeLayerView($request, $layer);

        $result = $this->features->list(
            $layer,
            (int) $request->integer('per_page', 25),
            $request->query('search')
        );

        return response()->json([
            'columns' => $result['columns'],
            'features' => $result['features'],
        ]);
    }

    public function show(Request $request, Layer $layer, int|string $feature)
    {
        $this->authorizeLayerView($request, $layer);

        $featureData = $this->features->find($layer, $feature);
        if (! $featureData) {
            return response()->json(['error' => 'Feature not found'], 404);
        }

        return response()->json(['data' => $featureData]);
    }

    public function store(Request $request, Layer $layer)
    {
        $this->authorizeLayerUpdate($request, $layer);

        $validated = $request->validate([
            'attributes' => 'nullable|array',
            'wkt' => 'nullable|string',
            'geometry' => 'nullable|array',
        ]);

        $created = $this->features->create(
            $layer,
            $validated['attributes'] ?? [],
            $validated['wkt'] ?? null,
            $validated['geometry'] ?? null
        );

        $this->webhooks->dispatch($layer->organization_id, 'feature.created', [
            'layer_id' => $layer->id,
            'feature' => $created,
        ]);

        return response()->json(['data' => $created], 201);
    }

    public function update(Request $request, Layer $layer, int|string $feature)
    {
        $this->authorizeLayerUpdate($request, $layer);

        $validated = $request->validate([
            'attributes' => 'nullable|array',
            'wkt' => 'nullable|string',
            'geometry' => 'nullable|array',
        ]);

        $updated = $this->features->update(
            $layer,
            $feature,
            $validated['attributes'] ?? [],
            $validated['wkt'] ?? null,
            $validated['geometry'] ?? null
        );

        if (! $updated) {
            return response()->json(['error' => 'Feature not found'], 404);
        }

        $this->webhooks->dispatch($layer->organization_id, 'feature.updated', [
            'layer_id' => $layer->id,
            'feature' => $updated,
        ]);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, Layer $layer, int|string $feature)
    {
        $this->authorizeLayerUpdate($request, $layer);

        if (! $this->features->delete($layer, $feature)) {
            return response()->json(['error' => 'Feature not found'], 404);
        }

        $this->webhooks->dispatch($layer->organization_id, 'feature.deleted', [
            'layer_id' => $layer->id,
            'feature_id' => (int) $feature,
        ]);

        return response()->json(['success' => true]);
    }

    protected function authorizeLayerView(Request $request, Layer $layer): void
    {
        $user = $request->user();

        if (! $this->access->canView($user, 'layer', $layer->id, $layer->organization_id)) {
            abort(403, 'You do not have access to this layer.');
        }
    }

    protected function authorizeLayerUpdate(Request $request, Layer $layer): void
    {
        $this->authorizeLayerView($request, $layer);
        $this->authorize('update', $layer);
    }
}
