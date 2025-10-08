<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'organization']);
    }

    /**
     * Show the organization settings page.
     */
    public function settings(Request $request)
    {
        $organization = $request->user()->organization;

        $this->authorize('view', $organization);

        return view('organization.settings', compact('organization'));
    }

    /**
     * Update the organization settings.
     */
    public function update(Request $request)
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $organization->update($validated);

        return redirect()->route('organization.settings')
            ->with('success', 'Organization settings updated successfully.');
    }
}
