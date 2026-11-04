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
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Services\ShelterOccupancyService;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\ShelterCheckinDirection;

class ShelterCheckinController extends Controller
{
    use RespondsToDisasterConsole;

    public function store(Request $request, ShelterOccupancyService $occupancy): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.registry');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'shelter_site_id' => [
                'required',
                'integer',
                Rule::exists('dm_critical_sites', 'id')->where(fn ($q) => $q
                    ->where('organization_id', $organizationId)
                    ->where('type', CriticalSiteType::Shelter->value)),
            ],
            'direction' => ['required', Rule::enum(ShelterCheckinDirection::class)],
            'headcount' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'beneficiary_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_beneficiaries', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'checked_at' => ['nullable', 'date'],
        ]);

        $shelter = CriticalSite::query()
            ->forOrganization($organizationId)
            ->whereKey($data['shelter_site_id'])
            ->firstOrFail();

        $beneficiary = isset($data['beneficiary_id'])
            ? Beneficiary::query()->forOrganization($organizationId)->whereKey($data['beneficiary_id'])->first()
            : null;

        $direction = $data['direction'] instanceof ShelterCheckinDirection
            ? $data['direction']
            : ShelterCheckinDirection::from((string) $data['direction']);

        $result = $occupancy->record(
            $shelter,
            $direction,
            (int) ($data['headcount'] ?? 1),
            $request->user(),
            $beneficiary,
            $data['notes'] ?? null,
            isset($data['checked_at']) ? new \DateTimeImmutable($data['checked_at']) : null,
        );

        $payload = [
            'checkin' => [
                'id' => $result['checkin']->id,
                'shelter_site_id' => $result['checkin']->shelter_site_id,
                'direction' => $result['checkin']->direction instanceof ShelterCheckinDirection
                    ? $result['checkin']->direction->value
                    : $result['checkin']->direction,
                'headcount' => (int) $result['checkin']->headcount,
                'checked_at' => $result['checkin']->checked_at?->toIso8601String(),
            ],
            'shelter' => [
                'id' => $result['shelter']->id,
                'site_id' => $result['shelter']->site_id,
                'current_occupancy' => (int) $result['shelter']->current_occupancy,
                'capacity' => $result['shelter']->capacity,
            ],
            'capacity_warning' => $result['capacity_warning'],
        ];

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $payload], 201);
        }

        return redirect()
            ->route('disaster.registry.index')
            ->with('success', 'Shelter occupancy updated.')
            ->with('capacity_warning', $result['capacity_warning']);
    }
}
