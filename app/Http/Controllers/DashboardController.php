<?php

namespace App\Http\Controllers;

use App\Models\Project;
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

        return view('dashboard', compact('user', 'organization', 'projects'));
    }
}
