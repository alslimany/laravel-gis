<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\InfrastructureCondition;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;

class InfrastructureConditionController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => InfrastructureCondition::query()
                ->forOrganization($organizationId)
                ->with('site:id,site_id,name,type')
                ->orderByDesc('last_inspection_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => InfrastructureCondition::query()
                ->forOrganization($organizationId)
                ->overdue()
                ->with('site:id,site_id,name,type')
                ->orderBy('next_inspection_due')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request, $organizationId);

        $condition = InfrastructureCondition::query()->create([
            ...$data,
            'organization_id' => $organizationId,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $condition->load('site:id,site_id,name,type')], 201);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Infrastructure condition recorded.');
    }

    public function update(Request $request, InfrastructureCondition $infrastructureCondition): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $infrastructureCondition);
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request, $organizationId, updating: true);
        $infrastructureCondition->fill($data);
        $infrastructureCondition->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $infrastructureCondition->load('site:id,site_id,name,type')]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Infrastructure condition updated.');
    }

    public function destroy(Request $request, InfrastructureCondition $infrastructureCondition): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $infrastructureCondition);
        $infrastructureCondition->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Infrastructure condition deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, int $organizationId, bool $updating = false): array
    {
        return $request->validate([
            'site_id' => [
                $updating ? 'sometimes' : 'required',
                'integer',
                Rule::exists('dm_critical_sites', 'id')->where('organization_id', $organizationId),
            ],
            'condition_grade' => [$updating ? 'sometimes' : 'required', Rule::enum(InfrastructureConditionGrade::class)],
            'last_inspection_at' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
            'maintenance_status' => ['nullable', 'string', 'max:64'],
            'inspector' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    protected function assertOrg(Request $request, InfrastructureCondition $condition): void
    {
        abort_unless((int) $condition->organization_id === (int) $request->user()->organization_id, 404);
    }
}
