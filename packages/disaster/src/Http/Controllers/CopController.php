<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Services\CopSnapshotBuilder;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\DisasterMapConfig;
use Lumina\Disaster\Support\Enums\Lifeline;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\Pdf\PdfRenderer;

class CopController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;
        $staleAfter = (int) config('disaster.cop.stale_after_seconds', 30);

        $snapshot = (new CopSnapshotBuilder($organizationId, $request->user(), $staleAfter))->build();

        return Inertia::render('Disaster/Cop', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            ...$snapshot,
            'org_map' => DisasterMapConfig::forOrganization($organizationId),
            'export_url' => route('disaster.cop.export'),
            'component_store_url' => route('disaster.cop.components.store'),
            'status_store_url' => route('disaster.cop.status.store'),
            'lifeline_options' => ConsoleAuth::enumOptions(Lifeline::class),
            'status_options' => ConsoleAuth::enumOptions(LifelineComponentStatus::class),
        ]);
    }

    public function export(Request $request): HttpResponse|RedirectResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $staleAfter = (int) config('disaster.cop.stale_after_seconds', 30);
        $snapshot = (new CopSnapshotBuilder($organizationId, $request->user(), $staleAfter))->build();
        $filename = 'cop-snapshot-'.now()->format('Ymd-His').'.pdf';

        return PdfRenderer::download('disaster::cop', [
            'snapshot' => $snapshot,
            'organization' => $request->user()->organization,
        ], $filename);
    }

    public function storeComponent(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'lifeline' => ['required', 'string', Rule::in(ConsoleAuth::enumValues(Lifeline::class))],
            'name' => ['required', 'string', 'max:255'],
            'linked_asset_type' => ['nullable', 'string', 'max:64'],
            'linked_asset_id' => ['nullable', 'integer', 'min:1'],
            'layer_id' => ['nullable', 'integer', 'min:1'],
            'layer_feature_id' => ['nullable', 'integer', 'min:1'],
            'initial_status' => ['nullable', 'string', Rule::in(ConsoleAuth::enumValues(LifelineComponentStatus::class))],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $component = LifelineComponent::query()->create([
            'organization_id' => $organizationId,
            'lifeline' => $data['lifeline'],
            'name' => $data['name'],
            'linked_asset_type' => $data['linked_asset_type'] ?? null,
            'linked_asset_id' => $data['linked_asset_id'] ?? null,
            'layer_id' => $data['layer_id'] ?? null,
            'layer_feature_id' => $data['layer_feature_id'] ?? null,
        ]);

        LifelineStatus::query()->create([
            'organization_id' => $organizationId,
            'component_id' => $component->id,
            'status' => $data['initial_status'] ?? LifelineComponentStatus::Unknown->value,
            'reason' => $data['reason'] ?? null,
            'reported_by' => $request->user()->id,
            'reported_at' => now(),
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $component->load('latestStatus')], 201);
        }

        return redirect()->route('disaster.cop.index')->with('success', 'Lifeline component added.');
    }

    public function storeStatus(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('disaster.field');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'component_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(ConsoleAuth::enumValues(LifelineComponentStatus::class))],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $component = LifelineComponent::query()
            ->forOrganization($organizationId)
            ->whereKey($data['component_id'])
            ->firstOrFail();

        $status = LifelineStatus::query()->create([
            'organization_id' => $organizationId,
            'component_id' => $component->id,
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
            'reported_by' => $request->user()->id,
            'reported_at' => now(),
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $status], 201);
        }

        return redirect()->route('disaster.cop.index')->with('success', 'Lifeline status recorded.');
    }
}
