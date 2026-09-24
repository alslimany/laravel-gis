<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Services\FeatureService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function __construct(
        protected FeatureService $features,
        protected WebhookDispatcher $webhooks
    ) {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    public function index(Request $request, Layer $layer)
    {
        $this->authorize('view', $layer);

        $result = $this->features->list(
            $layer,
            (int) $request->input('per_page', 25),
            $request->input('search')
        );

        return response()->json([
            'success' => true,
            'columns' => $result['columns'],
            'features' => $result['features'],
        ]);
    }

    public function show(Layer $layer, int|string $featureId)
    {
        $this->authorize('view', $layer);

        $feature = $this->features->find($layer, $featureId);
        if (! $feature) {
            return response()->json(['error' => 'Feature not found'], 404);
        }

        return response()->json(['success' => true, 'feature' => $feature]);
    }

    public function store(Request $request, Layer $layer)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'attributes' => 'nullable|array',
            'wkt' => 'nullable|string',
            'geometry' => 'nullable|array',
        ]);

        if (empty($validated['wkt']) && empty($validated['geometry'])) {
            return response()->json(['error' => 'wkt or geometry is required'], 422);
        }

        try {
            $feature = $this->features->create(
                $layer,
                $validated['attributes'] ?? [],
                $validated['wkt'] ?? null,
                $validated['geometry'] ?? null
            );

            $this->webhooks->dispatch($layer->organization_id, 'feature.created', [
                'layer_id' => $layer->id,
                'feature' => $feature,
            ]);

            return response()->json(['success' => true, 'feature' => $feature], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Layer $layer, int|string $featureId)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'attributes' => 'nullable|array',
            'wkt' => 'nullable|string',
            'geometry' => 'nullable|array',
        ]);

        try {
            $feature = $this->features->update(
                $layer,
                $featureId,
                $validated['attributes'] ?? [],
                $validated['wkt'] ?? null,
                $validated['geometry'] ?? null
            );

            if (! $feature) {
                return response()->json(['error' => 'Feature not found'], 404);
            }

            $this->webhooks->dispatch($layer->organization_id, 'feature.updated', [
                'layer_id' => $layer->id,
                'feature' => $feature,
            ]);

            return response()->json(['success' => true, 'feature' => $feature]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Layer $layer, int|string $featureId)
    {
        $this->authorize('update', $layer);

        $deleted = $this->features->delete($layer, $featureId);
        if (! $deleted) {
            return response()->json(['error' => 'Feature not found'], 404);
        }

        $this->webhooks->dispatch($layer->organization_id, 'feature.deleted', [
            'layer_id' => $layer->id,
            'feature_id' => (int) $featureId,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateFromTable(Request $request, Layer $layer, int|string $featureId)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'attributes' => 'required|array',
        ]);

        $feature = $this->features->update($layer, $featureId, $validated['attributes']);
        if (! $feature) {
            return back()->withErrors(['feature' => 'Feature not found.']);
        }

        $this->webhooks->dispatch($layer->organization_id, 'feature.updated', [
            'layer_id' => $layer->id,
            'feature' => $feature,
        ]);

        return back();
    }

    public function destroyFromTable(Layer $layer, int|string $featureId)
    {
        $this->authorize('update', $layer);

        $deleted = $this->features->delete($layer, $featureId);
        if (! $deleted) {
            return back()->withErrors(['feature' => 'Feature not found.']);
        }

        $this->webhooks->dispatch($layer->organization_id, 'feature.deleted', [
            'layer_id' => $layer->id,
            'feature_id' => (int) $featureId,
        ]);

        return back();
    }

    public function destroyMany(Layer $layer, Request $request)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ]);

        $deleted = $this->features->deleteMany($layer, $validated['ids']);
        foreach ($deleted as $featureId) {
            $this->webhooks->dispatch($layer->organization_id, 'feature.deleted', [
                'layer_id' => $layer->id,
                'feature_id' => $featureId,
            ]);
        }

        return back()->with('success', count($deleted).' feature'.(count($deleted) === 1 ? '' : 's').' removed.');
    }

    public function destroyShape(Layer $layer, string $shape)
    {
        $this->authorize('update', $layer);

        try {
            $deleted = $this->features->deleteByShape($layer, $shape);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['shape' => $e->getMessage()]);
        }

        return back()->with('success', $deleted.' '.$shape.' feature'.($deleted === 1 ? '' : 's').' removed.');
    }

    public function geojson(Layer $layer)
    {
        $this->authorize('view', $layer);

        return response()->json($this->features->toGeoJson($layer));
    }
}
