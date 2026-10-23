<?php

namespace App\Http\Controllers;

use App\Services\GeoServerPublisher;
use App\Services\SolutionPackInstaller;
use App\SolutionPacks\PackCatalog;
use App\SolutionPacks\SolutionPack;
use App\SolutionPacks\StoredPack;
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
            'solutionPacks' => app(PackCatalog::class)->list($organization),
            'canInstallPacks' => $request->user()->hasRole('admin'),
        ]);
    }

    /**
     * Install a solution pack into the organization.
     */
    public function installSolutionPack(Request $request, string $slug)
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $pack = StoredPack::findFor($organization, $slug) ?? SolutionPack::find($slug);
        if (! $pack) {
            abort(404, 'Solution pack not found.');
        }

        $summary = app(SolutionPackInstaller::class)->install($pack, $organization, $request->user());

        $parts = [];
        $created = collect($summary['layers'])->where('created', true)->count();
        if ($created > 0) {
            $parts[] = "{$created} new layers";
        }
        if (count($summary['relationships']) > 0) {
            $parts[] = count($summary['relationships']).' relationships';
        }
        if (($summary['features'] ?? 0) > 0) {
            $parts[] = $summary['features'].' sample features';
        }
        foreach (['maps' => 'map', 'forms' => 'form', 'dashboards' => 'dashboard'] as $key => $singular) {
            $count = $summary[$key] ?? 0;
            if ($count > 0) {
                $parts[] = $count.' '.($count === 1 ? $singular : $singular.'s');
            }
        }

        $message = $summary['installed']
            ? "{$pack->name()} installed: ".implode(', ', $parts).'.'
            : "{$pack->name()} is already installed.";

        return redirect()->route('organization.settings')->with('success', $message);
    }

    /**
     * Publish the pack's unpublished layers to GeoServer.
     */
    public function publishSolutionPack(Request $request, string $slug)
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $pack = StoredPack::findFor($organization, $slug) ?? SolutionPack::find($slug);
        if (! $pack) {
            abort(404, 'Solution pack not found.');
        }

        $layers = array_values(array_filter(
            app(SolutionPackInstaller::class)->packLayers($pack, $organization),
            fn ($layer) => ! $layer->isPublished()
        ));

        if ($layers === []) {
            return redirect()
                ->route('organization.settings')
                ->with('error', "{$pack->name()} has no unpublished layers.");
        }

        $result = app(GeoServerPublisher::class)->publish($organization, $layers);

        if ($result['failed'] === []) {
            $count = $result['published'];
            $noun = $count === 1 ? 'layer' : 'layers';

            return redirect()
                ->route('organization.settings')
                ->with('success', "{$pack->name()}: published {$count} {$noun} to GeoServer.");
        }

        $message = "{$pack->name()}: published {$result['published']} of ".count($layers)
            .' layers. Failed: '.implode(', ', $result['failed']).'.';

        return redirect()
            ->route('organization.settings')
            ->with($result['published'] > 0 ? 'success' : 'error', $message);
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
