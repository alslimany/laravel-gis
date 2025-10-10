<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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

        $projects = $organization
            ? $organization->projects()->latest()->paginate(10)
            : collect();

        // GIS-specific statistics
        $stats = [];
        if ($organization) {
            $stats = [
                'total_layers' => $organization->layers()->count(),
                'published_layers' => $organization->layers()->where('published', true)->count(),
                'total_maps' => $organization->maps()->count(),
                'total_projects' => $organization->projects()->count(),
                'recent_layers' => $organization->layers()->latest()->take(5)->get(),
                'recent_maps' => $organization->maps()->latest()->take(5)->get(),
                'storage_usage' => $this->calculateStorageUsage($organization),
            ];
        }

        return view('dashboard', compact('user', 'organization', 'projects', 'stats'));
    }

    /**
     * Calculate approximate storage usage for organization layers
     */
    private function calculateStorageUsage($organization)
    {
        $totalFeatures = $organization->layers()->sum('feature_count');
        // Rough estimate: average 1KB per feature
        $estimatedBytes = $totalFeatures * 1024;
        
        if ($estimatedBytes < 1024 * 1024) {
            return number_format($estimatedBytes / 1024, 2) . ' KB';
        } elseif ($estimatedBytes < 1024 * 1024 * 1024) {
            return number_format($estimatedBytes / (1024 * 1024), 2) . ' MB';
        } else {
            return number_format($estimatedBytes / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }
}
