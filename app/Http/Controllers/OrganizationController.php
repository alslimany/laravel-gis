<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

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

        return Inertia::render('Organization/Settings', [
            'organization' => $organization,
        ]);
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
            'primary_color' => ['nullable', 'string', 'max:20', 'regex:/^#?[0-9A-Fa-f]{3,8}$/'],
            'logo' => 'nullable|image|mimes:jpeg,png,gif,webp,svg|max:2048',
        ]);

        if (! empty($validated['primary_color']) && ! str_starts_with($validated['primary_color'], '#')) {
            $validated['primary_color'] = '#'.$validated['primary_color'];
        }

        if ($request->hasFile('logo')) {
            if ($organization->logo_path) {
                Storage::disk('public')->delete($organization->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store('organization-logos', 'public');
        }

        unset($validated['logo']);

        $organization->update($validated);

        return redirect()->route('organization.settings')
            ->with('success', 'Organization settings updated successfully.');
    }
}
