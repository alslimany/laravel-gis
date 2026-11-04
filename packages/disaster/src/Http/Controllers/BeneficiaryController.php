<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Beneficiary;
use Lumina\Disaster\Services\BeneficiaryRegistryService;
use Lumina\Disaster\Support\Enums\CriticalSiteType;

class BeneficiaryController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request, BeneficiaryRegistryService $registry): JsonResponse
    {
        Gate::authorize('disaster.registry');

        $rows = $registry->listForOrganization(
            (int) $request->user()->organization_id,
            $request->user(),
            $request->ip(),
        );

        return response()->json([
            'data' => $rows->map(fn (Beneficiary $b) => $registry->present($b))->all(),
        ]);
    }

    public function show(Request $request, Beneficiary $beneficiary, BeneficiaryRegistryService $registry): JsonResponse
    {
        Gate::authorize('disaster.registry');
        $this->assertOrg($request, $beneficiary);

        $row = $registry->findForRead(
            (int) $request->user()->organization_id,
            (int) $beneficiary->id,
            $request->user(),
            $request->ip(),
        );

        return response()->json(['data' => $registry->present($row)]);
    }

    public function store(Request $request, BeneficiaryRegistryService $registry): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.registry');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request, $organizationId);

        $beneficiary = $registry->create($organizationId, $data, $request->user(), $request->ip());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $registry->present($beneficiary)], 201);
        }

        return redirect()->route('disaster.registry.index')->with('success', 'Beneficiary registered.');
    }

    public function export(Request $request, BeneficiaryRegistryService $registry): JsonResponse
    {
        Gate::authorize('disaster.registry');

        return response()->json([
            'data' => $registry->export(
                (int) $request->user()->organization_id,
                $request->user(),
                $request->ip(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, int $organizationId): array
    {
        return $request->validate([
            'household_code' => ['required', 'string', 'max:64'],
            'household_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'needs' => ['nullable', 'array'],
            'shelter_site_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_critical_sites', 'id')->where(fn ($q) => $q
                    ->where('organization_id', $organizationId)
                    ->where('type', CriticalSiteType::Shelter->value)),
            ],
            'contact_ref' => ['nullable', 'string', 'max:128'],
            'props' => ['nullable', 'array'],
            'retained_until' => ['nullable', 'date'],
        ]);
    }

    protected function assertOrg(Request $request, Beneficiary $beneficiary): void
    {
        abort_unless(
            (int) $beneficiary->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
