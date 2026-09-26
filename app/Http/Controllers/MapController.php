<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Models\Map;
use App\Services\ContentAccessService;
use App\Services\PublicShareMap;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MapController extends Controller
{
    /**
     * Display a listing of the maps
     */
    public function index()
    {
        $user = Auth::user();
        $access = app(ContentAccessService::class);
        $all = Map::where('organization_id', $user->organization_id)
            ->with('user')
            ->latest()
            ->get();
        $visible = $access->filterVisible($user, 'map', $all);
        $page = max(1, (int) request('page', 1));
        $maps = new LengthAwarePaginator(
            $visible->forPage($page, 15)->values(),
            $visible->count(),
            15,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return Inertia::render('Maps/Index', [
            'maps' => $maps,
        ]);
    }

    /**
     * Show the form for creating a new map
     */
    public function create()
    {
        return Inertia::render('Maps/Form');
    }

    /**
     * Display the map builder
     */
    public function builder(Request $request, $id = null)
    {
        $map = null;
        if ($id) {
            $map = Map::where('organization_id', Auth::user()->organization_id)
                ->findOrFail($id);
            $this->assertCanViewMap($map);
        }

        $seedLayer = null;
        if ($request->filled('layer')) {
            $seedLayer = Layer::where('organization_id', Auth::user()->organization_id)
                ->findOrFail((int) $request->query('layer'));
            $this->authorize('view', $seedLayer);
        }

        return Inertia::render('Maps/Builder', [
            'initialMap' => $this->initialMapData($map, $seedLayer),
        ]);
    }

    /**
     * Store a newly created map
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'viewport' => 'nullable|array',
            'basemap' => 'nullable|string',
            'layers' => 'nullable|array',
            'is_public' => 'boolean',
        ]);

        $validated['user_id'] = Auth::id();
        $validated['organization_id'] = Auth::user()->organization_id;

        $map = Map::create($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'map' => $map,
            ]);
        }

        return redirect()->route('maps.show', $map)
            ->with('success', 'Map created successfully!');
    }

    /**
     * Display the specified map
     */
    public function show(Map $map)
    {
        if ($map->organization_id !== Auth::user()->organization_id && ! $map->is_public) {
            abort(403);
        }
        $this->assertCanViewMap($map);

        return Inertia::render('Maps/Show', [
            'initialMap' => $this->initialMapData($map->load('user'), null),
        ]);
    }

    /**
     * Show the form for editing the specified map
     */
    public function edit(Map $map)
    {
        if ($map->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }
        $this->assertCanViewMap($map);

        return Inertia::render('Maps/Form', [
            'map' => $map,
        ]);
    }

    /**
     * Update the specified map
     */
    public function update(Request $request, Map $map)
    {
        // Check access
        if ($map->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'viewport' => 'nullable|array',
            'basemap' => 'nullable|string',
            'layers' => 'nullable|array',
            'is_public' => 'boolean',
        ]);

        $map->update($validated);
        $this->ensureShareToken($map);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'map' => $map->fresh(),
            ]);
        }

        if ($request->boolean('return_to_share')) {
            return redirect()->route('maps.share', $map)
                ->with('success', 'Sharing updated.');
        }

        return redirect()->route('maps.show', $map)
            ->with('success', 'Map updated successfully!');
    }

    /**
     * Remove the specified map
     */
    public function destroy(Map $map)
    {
        // Check access
        if ($map->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $map->delete();

        return redirect()->route('maps.index')
            ->with('success', 'Map deleted successfully!');
    }

    /**
     * Share a map (generate or update share token)
     */
    public function share(Map $map)
    {
        // Check access
        if ($map->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        $this->ensureShareToken($map);

        return Inertia::render('Maps/Share', [
            'map' => $map->fresh(),
        ]);
    }

    /**
     * View a shared map via token
     */
    public function viewShared($token)
    {
        $map = Map::where('share_token', $token)
            ->where('is_public', true)
            ->firstOrFail();

        return Inertia::render('Maps/Shared', [
            'map' => app(PublicShareMap::class)->present($map),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function initialMapData(?Map $map, ?Layer $seedLayer): array
    {
        $seedLayers = [];
        $seedViewport = null;
        $seedBasemap = 'osm';

        if ($seedLayer) {
            $isRaster = $seedLayer->geometry_type === 'Raster';
            $seedBasemap = $isRaster ? 'imagery' : 'osm';

            if ($isRaster) {
                $workspace = $seedLayer->geoserver_workspace;
                $coverage = $seedLayer->geoserver_layer_name ?: $seedLayer->table_name;
                $seedLayers[] = [
                    'id' => $seedLayer->id,
                    'name' => $seedLayer->name,
                    'type' => 'wms',
                    'visible' => true,
                    'url' => rtrim((string) config('geoserver.public_url'), '/').'/wms',
                    'layers' => $workspace ? $workspace.':'.$coverage : $coverage,
                    'wmsParams' => data_get($seedLayer->metadata, 'wms_params', ['SORTING' => 'acquired D']),
                    'geometry_type' => 'Raster',
                    'opacity' => 1,
                ];
                $bbox = data_get($seedLayer->metadata, 'bbox');
                if (is_array($bbox) && count($bbox) === 4) {
                    $seedViewport = [
                        'center' => [($bbox[0] + $bbox[2]) / 2, ($bbox[1] + $bbox[3]) / 2],
                        'zoom' => 12,
                        'rotation' => 0,
                    ];
                }
            } else {
                $seedLayers[] = [
                    'id' => $seedLayer->id,
                    'name' => $seedLayer->name,
                    'type' => 'mvt',
                    'visible' => true,
                    'mvtUrl' => "/api/layers/{$seedLayer->id}/tiles/{z}/{x}/{y}.mvt",
                    'style_config' => $seedLayer->style_config ?: ['renderer' => 'simple'],
                    'geometry_type' => $seedLayer->geometry_type,
                ];
            }
        }

        $initialLayers = $map && is_array($map->layers) ? $map->layers : $seedLayers;
        if ($seedLayer && $map && is_array($map->layers)) {
            $ids = collect($map->layers)->pluck('id')->all();
            if (! in_array($seedLayer->id, $ids, true)) {
                $initialLayers = array_merge($seedLayers, $map->layers);
            }
        }

        if ($map) {
            return [
                'id' => $map->id,
                'name' => $map->name,
                'description' => $map->description ?? '',
                'viewport' => $map->viewport,
                'basemap' => $map->basemap,
                'layers' => $initialLayers,
            ];
        }

        return [
            'id' => null,
            'name' => $seedLayer?->name ?: 'Untitled map',
            'description' => '',
            'viewport' => $seedViewport,
            'basemap' => $seedBasemap,
            'layers' => $initialLayers,
        ];
    }

    protected function ensureShareToken(Map $map): void
    {
        if ($map->share_token) {
            return;
        }

        $map->forceFill(['share_token' => Str::random(32)])->save();
    }

    protected function assertCanViewMap(Map $map): void
    {
        if (! app(ContentAccessService::class)->canView(
            Auth::user(),
            'map',
            $map->id,
            $map->organization_id
        )) {
            abort(403);
        }
    }
}
