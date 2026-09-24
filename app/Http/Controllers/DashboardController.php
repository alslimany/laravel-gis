<?php

namespace App\Http\Controllers;

use App\Models\DataImport;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $organization = $user->organization;

        $projects = [];
        $stats = null;

        if ($organization) {
            $projectPaginator = $organization->projects()->latest()->paginate(10);
            $projects = [
                'data' => $projectPaginator->getCollection()->map(fn ($project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'created_at_formatted' => optional($project->created_at)->format('M d, Y'),
                ])->values()->all(),
            ];

            $stats = [
                'import_count' => DataImport::query()->where('organization_id', $organization->id)->count(),
                'total_layers' => $organization->layers()->count(),
                'published_layers' => $organization->layers()->where('published', true)->count(),
                'total_maps' => $organization->maps()->count(),
                'total_projects' => $organization->projects()->count(),
                'recent_layers' => $organization->layers()->latest()->take(5)->get()->map(fn ($layer) => [
                    'id' => $layer->id,
                    'name' => $layer->name,
                    'geometry_type' => $layer->geometry_type,
                    'feature_count' => $layer->feature_count,
                    'published' => (bool) $layer->published,
                    'created_human' => optional($layer->created_at)->diffForHumans(),
                ])->values()->all(),
                'recent_maps' => $organization->maps()->latest()->take(5)->get()->map(fn ($map) => [
                    'id' => $map->id,
                    'name' => $map->name,
                    'layers' => $map->layers ?? [],
                    'is_public' => (bool) $map->is_public,
                    'created_human' => optional($map->created_at)->diffForHumans(),
                    'viewport' => $map->viewport,
                    'basemap' => $map->basemap ?: 'osm',
                ])->values()->all(),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'projects' => $projects,
        ]);
    }

}
