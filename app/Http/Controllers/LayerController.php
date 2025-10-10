<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LayerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Layer::class);

        $layers = Layer::where('organization_id', Auth::user()->organization_id)
            ->with(['user', 'project', 'organization'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('layers.index', compact('layers'));
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

        return view('layers.create', compact('projects'));
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

        $layer->load(['user', 'project', 'organization']);

        return view('layers.show', compact('layer'));
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

        return view('layers.edit', compact('layer', 'projects'));
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
        if ($layer->isPublished()) {
            try {
                $organization = $layer->organization;
                if (method_exists($organization, 'deleteLayerFromGeoServer')) {
                    $organization->deleteLayerFromGeoServer($layer->geoserver_layer_name);
                }
            } catch (\Exception $e) {
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

        try {
            $organization = $layer->organization;
            $workspace = $organization->getGeoServerWorkspace();
            
            // Publish to GeoServer
            $organization->publishLayerToGeoServer($layer->table_name, [
                'title' => $layer->name,
                'abstract' => $layer->description ?? "Layer: {$layer->name}",
                'srs' => 'EPSG:4326',
            ]);

            // Mark as published
            $layer->markAsPublished($layer->table_name, $workspace);

            return redirect()
                ->route('layers.show', $layer)
                ->with('success', 'Layer published to GeoServer successfully.');
        } catch (\Exception $e) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Failed to publish layer: ' . $e->getMessage());
        }
    }

    /**
     * Unpublish layer from GeoServer.
     */
    public function unpublish(Layer $layer)
    {
        $this->authorize('update', $layer);

        if (!$layer->isPublished()) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Layer is not published.');
        }

        try {
            $organization = $layer->organization;
            $organization->deleteLayerFromGeoServer($layer->geoserver_layer_name);

            // Mark as unpublished
            $layer->markAsUnpublished();

            return redirect()
                ->route('layers.show', $layer)
                ->with('success', 'Layer unpublished from GeoServer successfully.');
        } catch (\Exception $e) {
            return redirect()
                ->route('layers.show', $layer)
                ->with('error', 'Failed to unpublish layer: ' . $e->getMessage());
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
                    $styleName = $layer->geoserver_layer_name . '_style';
                    $organization->updateLayerStyle(
                        $layer->geoserver_layer_name,
                        $styleName,
                        $sldContent
                    );
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to update style in GeoServer: ' . $e->getMessage());
                // Style is still saved to DB
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'style' => $styleConfig
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
        $fillColor = $styleConfig['fill_color'] ?? '#0000ff';
        $strokeColor = $styleConfig['stroke_color'] ?? '#000000';
        $strokeWidth = $styleConfig['stroke_width'] ?? 1;
        $fillOpacity = $styleConfig['fill_opacity'] ?? 0.5;
        
        return <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0" xmlns="http://www.opengis.net/sld">
  <NamedLayer>
    <Name>{$layer->geoserver_layer_name}</Name>
    <UserStyle>
      <Name>custom_style</Name>
      <FeatureTypeStyle>
        <Rule>
          <PolygonSymbolizer>
            <Fill>
              <CssParameter name="fill">{$fillColor}</CssParameter>
              <CssParameter name="fill-opacity">{$fillOpacity}</CssParameter>
            </Fill>
            <Stroke>
              <CssParameter name="stroke">{$strokeColor}</CssParameter>
              <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
            </Stroke>
          </PolygonSymbolizer>
        </Rule>
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;
    }
}
