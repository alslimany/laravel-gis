<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Contracts\LayerCatalog;
use App\Models\OrganizationPlugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\OrganizationDisasterSettings;

class HazardSettingsController extends Controller
{
    public function show(Request $request, LayerCatalog $layerCatalog): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $settings = $this->settingsFor($organizationId);

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['settings' => $settings]);
        }

        $layers = $layerCatalog->forOrganization($organizationId)
            ->map(fn ($layer) => ['id' => $layer->id, 'name' => $layer->name])
            ->values()
            ->all();

        return Inertia::render('Disaster/Hazards', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'settings' => $settings,
            'layers' => $layers,
            'update_url' => route('disaster.hazards.update'),
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('disaster.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'floodplain_layer_id' => ['nullable', 'integer', 'min:1'],
            'fault_layer_id' => ['nullable', 'integer', 'min:1'],
            'wildfire_layer_id' => ['nullable', 'integer', 'min:1'],
            'demographic_layer_id' => ['nullable', 'integer', 'min:1'],
            'buildings_layer_id' => ['nullable', 'integer', 'min:1'],
            'roads_layer_id' => ['nullable', 'integer', 'min:1'],
            'ew_risk_layer_id' => ['nullable', 'integer', 'min:1'],
            'default_hazard_level' => ['nullable', 'numeric', 'between:0,1'],
            'risk_weights' => ['nullable', 'array'],
            'avl_max_skew_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'retention_avl_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_official_authority' => ['sometimes', 'boolean'],
        ]);

        $row = OrganizationPlugin::query()->firstOrNew([
            'organization_id' => $organizationId,
            'plugin_key' => 'disaster',
        ]);
        if (! $row->exists) {
            $row->forceFill(['enabled' => true, 'enabled_at' => now()]);
        }
        $settings = is_array($row->settings) ? $row->settings : [];
        $row->settings = array_merge($settings, $data);
        $row->save();

        if ($request->expectsJson()) {
            return response()->json(['settings' => $row->settings]);
        }

        return redirect()->route('disaster.hazards.index')->with('success', 'Hazard layer settings saved.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsFor(int $organizationId): array
    {
        return OrganizationDisasterSettings::for($organizationId);
    }
}
