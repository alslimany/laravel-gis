<?php

namespace App\Http\Controllers;

use App\Models\Map;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapController extends Controller
{
    /**
     * Display a listing of the maps
     */
    public function index()
    {
        $maps = Map::where('organization_id', Auth::user()->organization_id)
            ->with('user')
            ->latest()
            ->paginate(15);

        return view('maps.index', compact('maps'));
    }

    /**
     * Show the form for creating a new map
     */
    public function create()
    {
        return view('maps.create');
    }

    /**
     * Display the map builder
     */
    public function builder($id = null)
    {
        $map = null;
        if ($id) {
            $map = Map::where('organization_id', Auth::user()->organization_id)
                ->findOrFail($id);
        }

        return view('maps.builder', compact('map'));
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
            'is_public' => 'boolean'
        ]);

        $validated['user_id'] = Auth::id();
        $validated['organization_id'] = Auth::user()->organization_id;

        $map = Map::create($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'map' => $map
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
        // Check access
        if ($map->organization_id !== Auth::user()->organization_id && !$map->is_public) {
            abort(403);
        }

        return view('maps.show', compact('map'));
    }

    /**
     * Show the form for editing the specified map
     */
    public function edit(Map $map)
    {
        // Check access
        if ($map->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        return view('maps.edit', compact('map'));
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
            'is_public' => 'boolean'
        ]);

        $map->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'map' => $map
            ]);
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

        return view('maps.share', compact('map'));
    }

    /**
     * View a shared map via token
     */
    public function viewShared($token)
    {
        $map = Map::where('share_token', $token)
            ->where('is_public', true)
            ->firstOrFail();

        return view('maps.shared', compact('map'));
    }
}
