<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteLayerFromGeoServer;
use App\Jobs\PublishLayerToGeoServer;
use App\Models\Layer;
use App\Models\Project;
use App\Services\ContentAccessService;
use App\Services\FeatureService;
use App\Services\SldGenerator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LayerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Layer::class);

        $user = Auth::user();
        $access = app(ContentAccessService::class);
        $jsonCatalog = ($request->expectsJson() || $request->is('api/*')) && ! $request->header('X-Inertia');
        $query = Layer::where('organization_id', $user->organization_id)
            ->with(['user', 'project', 'organization'])
            ->orderBy('created_at', 'desc');

        if ($jsonCatalog && $request->boolean('published')) {
            $query->where('published', true);
        }

        $all = $query->get();
        $visible = $access->filterVisible($user, 'layer', $all);

        if ($jsonCatalog && $request->boolean('published')) {
            return response()->json([
                'data' => $visible->values(),
            ]);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 15;
        $layers = new LengthAwarePaginator(
            $visible->forPage($page, $perPage)->values(),
            $visible->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        if (($request->expectsJson() || $request->is('api/*')) && ! $request->header('X-Inertia')) {
            return response()->json($layers);
        }

        return Inertia::render('Layers/Index', [
            'layers' => $layers,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', Layer::class);

        $projects = Project::where('organization_id', Auth::user()->organization_id)
            ->orderBy('name')
            ->get();

        return Inertia::render('Layers/Create', [
            'projects' => $projects,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Layer::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|exists:projects,id',
            'table_name' => 'required|string|max:255',
            'geometry_type' => 'nullable|string|max:50',
            'style_config' => 'nullable|array',
        ]);

        $user = Auth::user();

        $layer = Layer::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'table_name' => $validated['table_name'],
            'geometry_type' => $validated['geometry_type'] ?? null,
            'style_config' => $validated['style_config'] ?? [],
        ]);

        // Count features in the table
        try {
            $count = DB::table($layer->table_name)->count();
            $layer->update(['feature_count' => $count]);
        } catch (\Exception $e) {
            // Table might not exist yet
        }

        return redirect()
            ->route('layers.show', $layer)
            ->with('success', 'Layer created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Layer $layer)
    {
        $this->authorize('view', $layer);

        $layer->load(['user', 'project', 'organization', 'fields']);

        $shapes = [];
        if ($layer->table_name) {
            try {
                $shapes = app(FeatureService::class)->shapeCounts($layer->table_name);
            } catch (\Throwable) {
                $shapes = [];
            }
        }

        return Inertia::render('Layers/Show', [
            'layer' => $layer,
            'shapes' => $shapes,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Layer $layer)
    {
        $this->authorize('update', $layer);

        $projects = Project::where('organization_id', Auth::user()->organization_id)
            ->orderBy('name')
            ->get();

        return Inertia::render('Layers/Edit', [
            'layer' => $layer,
            'projects' => $projects,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Layer $layer)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|exists:projects,id',
            'style_config' => 'nullable|array',
        ]);

        $layer->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'style_config' => $validated['style_config'] ?? $layer->style_config,
        ]);

        return redirect()
            ->route('layers.show', $layer)
            ->with('success', 'Layer updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Layer $layer)
    {
        $this->authorize('delete', $layer);

        // Delete from GeoServer if published
        if ($layer->isPublished() && filled($layer->geoserver_layer_name)) {
            try {
                $organization = $layer->organization;
                if (method_exists($organization, 'deleteLayerFromGeoServer')) {
                    $organization->deleteLayerFromGeoServer($layer->geoserver_layer_name);
                }
            } catch (\Throwable $e) {
                // Log error but continue with deletion
            }
        }

        $layer->delete();

        return redirect()
            ->route('layers.index')
            ->with('success', 'Layer deleted successfully.');
    }

    /**
     * Publish layer to GeoServer.
     */
    public function publish(Layer $layer)
    {
        $this->authorize('update', $layer);

        if ($layer->isPublished()) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Layer is already published.');
        }

        if (blank($layer->table_name)) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'This layer has no data table to publish.');
        }

        try {
            $organization = $layer->organization;
            $workspace = $organization->getGeoServerWorkspace();
            $datastore = (string) Config::get('geoserver.datastore');

            // Wait for GeoServer in this request so a failure stays a draft
            // instead of a published layer whose job later dies on the queue.
            PublishLayerToGeoServer::dispatchSync(
                $workspace,
                $datastore,
                $layer->table_name,
                [
                    'title' => $layer->name,
                    'abstract' => $layer->description ?? "Layer: {$layer->name}",
                    'srs' => 'EPSG:4326',
                ]
            );

            $layer->markAsPublished($layer->table_name, $workspace);

            return redirect()
                ->route('layers.show', $layer)
                ->with('success', 'Layer published to GeoServer successfully.');
        } catch (\Exception $e) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Failed to publish layer: '.$e->getMessage());
        }
    }

    /**
     * Unpublish layer from GeoServer.
     */
    public function unpublish(Layer $layer)
    {
        $this->authorize('update', $layer);

        if (! $layer->isPublished()) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Layer is not published.');
        }

        try {
            $organization = $layer->organization;
            DeleteLayerFromGeoServer::dispatchSync(
                $organization->getGeoServerWorkspace(),
                (string) Config::get('geoserver.datastore'),
                (string) $layer->geoserver_layer_name
            );

            $layer->markAsUnpublished();

            return redirect()
                ->route('layers.show', $layer)
                ->with('success', 'Layer unpublished from GeoServer successfully.');
        } catch (\Exception $e) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Failed to unpublish layer: '.$e->getMessage());
        }
    }

    /**
     * Update layer style.
     */
    public function updateStyle(Request $request, Layer $layer)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'style_config' => 'required|array',
            'fillColor' => 'nullable|string',
            'strokeColor' => 'nullable|string',
            'strokeWidth' => 'nullable|numeric',
            'fillOpacity' => 'nullable|numeric',
        ]);

        // Merge style config
        $styleConfig = array_merge(
            $layer->style_config ?? [],
            $validated['style_config'] ?? []
        );

        // Add individual style properties if provided
        if (isset($validated['fillColor'])) {
            $styleConfig['fill_color'] = $validated['fillColor'];
        }
        if (isset($validated['strokeColor'])) {
            $styleConfig['stroke_color'] = $validated['strokeColor'];
        }
        if (isset($validated['strokeWidth'])) {
            $styleConfig['stroke_width'] = $validated['strokeWidth'];
        }
        if (isset($validated['fillOpacity'])) {
            $styleConfig['fill_opacity'] = $validated['fillOpacity'];
        }

        $layer->update([
            'style_config' => $styleConfig,
        ]);

        // If published, update style in GeoServer
        if ($layer->isPublished()) {
            try {
                $organization = $layer->organization;
                // Generate SLD from style config
                $sldContent = $this->generateSLD($layer, $styleConfig);

                // Update style in GeoServer if method exists
                if (method_exists($organization, 'updateLayerStyle')) {
                    $styleName = $layer->geoserver_layer_name.'_style';
                    $organization->updateLayerStyle(
                        $layer->geoserver_layer_name,
                        $styleName,
                        $sldContent
                    );
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to update style in GeoServer: '.$e->getMessage());
                // Style is still saved to DB
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'style' => $styleConfig,
            ]);
        }

        return redirect()
            ->route('layers.show', $layer)
            ->with('success', 'Layer style updated successfully.');
    }

    /**
     * Generate SLD XML for GeoServer from style config.
     */
    protected function generateSLD(Layer $layer, array $styleConfig)
    {
        return app(SldGenerator::class)->generate($layer, $styleConfig);
    }
}
