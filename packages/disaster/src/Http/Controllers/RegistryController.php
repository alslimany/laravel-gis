<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Services\BeneficiaryRegistryService;
use Lumina\Disaster\Services\MissingPersonsService;
use Lumina\Disaster\Services\ShelterOccupancyService;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\MissingPersonStatus;
use Lumina\Disaster\Support\Enums\PersonReportType;
use Lumina\Disaster\Support\Enums\ShelterCheckinDirection;

class RegistryController extends Controller
{
    public function index(
        Request $request,
        ShelterOccupancyService $occupancy,
        BeneficiaryRegistryService $beneficiaries,
        MissingPersonsService $missing,
    ): Response {
        Gate::authorize('disaster.registry');

        $organizationId = (int) $request->user()->organization_id;
        $ip = $request->ip();

        $shelters = CriticalSite::query()
            ->forOrganization($organizationId)
            ->where('type', CriticalSiteType::Shelter->value)
            ->orderBy('name')
            ->get(['id', 'site_id', 'name', 'capacity', 'current_occupancy']);

        return Inertia::render('Disaster/Registry', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'occupancy' => $occupancy->publicAggregates($organizationId),
            'shelters' => $shelters->map(fn (CriticalSite $s) => [
                'id' => $s->id,
                'site_id' => $s->site_id,
                'name' => $s->name,
                'capacity' => $s->capacity,
                'current_occupancy' => (int) $s->current_occupancy,
                'capacity_warning' => $occupancy->capacityWarning($s),
            ])->all(),
            'beneficiaries' => $beneficiaries->listForOrganization($organizationId, $request->user(), $ip)
                ->map(fn ($b) => $beneficiaries->present($b))
                ->all(),
            'missing_persons' => $missing->listForOrganization($organizationId, $request->user(), $ip)
                ->map(fn ($c) => $missing->presentStaff($c))
                ->all(),
            'direction_options' => ConsoleAuth::enumOptions(ShelterCheckinDirection::class),
            'status_options' => ConsoleAuth::enumOptions(MissingPersonStatus::class),
            'report_type_options' => ConsoleAuth::enumOptions(PersonReportType::class),
            'checkin_url' => route('disaster.registry.checkins.store'),
            'beneficiaries_store_url' => route('disaster.registry.beneficiaries.store'),
            'beneficiaries_export_url' => route('disaster.registry.beneficiaries.export'),
            'missing_store_url' => route('disaster.registry.missing.store'),
            'missing_match_url' => route('disaster.registry.missing.match'),
            'reports_store_url' => route('disaster.registry.reports.store'),
        ]);
    }
}
