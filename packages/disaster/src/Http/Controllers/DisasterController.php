<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\DamageFormSchema;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\EvacAssignment;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\ExposureSummary;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentImagery;
use Lumina\Disaster\Models\InfrastructureCondition;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Services\DamageAggregationService;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\DisasterMapConfig;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\GeneratorStatus;
use Lumina\Disaster\Support\Enums\Habitability;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\GeometryReader;
use Lumina\Disaster\Support\PresentsAssetRows;

class DisasterController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        $summary = [
            'plugin' => 'disaster',
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'counts' => [
                'medical_facilities' => MedicalFacility::query()->forOrganization($organizationId)->count(),
                'staging_areas' => StagingArea::query()->forOrganization($organizationId)->count(),
                'critical_sites' => CriticalSite::query()->forOrganization($organizationId)->count(),
                'open_incidents' => Incident::query()->forOrganization($organizationId)->where('status', 'open')->count(),
                // Same set the COP shows as current: proposed + active (a live activation must count).
                'activations' => Activation::query()->forOrganization($organizationId)->whereIn('status', ['proposed', 'active'])->count(),
                'proposed_activations' => Activation::query()->forOrganization($organizationId)->where('status', 'proposed')->count(),
                'avl_units' => AvlUnit::query()->forOrganization($organizationId)->count(),
                'damage_points' => DamagePoint::query()->forOrganization($organizationId)->count(),
                'closures' => Closure::query()->forOrganization($organizationId)->count(),
                'exposure_summaries' => ExposureSummary::query()->forOrganization($organizationId)->count(),
            ],
            'recent_cap_logs' => DisseminationLog::query()
                ->forOrganization($organizationId)
                ->where('channel', 'cap')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'channel', 'payload', 'created_at']),
        ];

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json($summary);
        }

        return Inertia::render('Disaster/Index', [
            ...$summary,
            'hazardsUrl' => route('disaster.hazards.index'),
            'assetsUrl' => route('disaster.assets.index'),
            'planningUrl' => route('disaster.planning.index'),
            'incidentsUrl' => route('disaster.incidents.index'),
            'activationsUrl' => route('disaster.activations.index'),
            'damageUrl' => route('disaster.damage.index'),
            'exposureUrl' => route('disaster.exposure.page'),
            'avlUrl' => route('disaster.avl.page'),
            'publicTokensUrl' => route('disaster.public-tokens.index'),
            'closuresUrl' => route('disaster.closures.index'),
            'cap_draft_url' => route('disaster.dissemination.cap.store'),
        ]);
    }

    public function damage(Request $request, DamageAggregationService $aggregation): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $points = DamagePoint::query()
            ->forOrganization($organizationId)
            ->withCount('photos')
            ->orderByDesc('assessed_at')
            ->get()
            ->map(function (DamagePoint $point) {
                $row = $point->only([
                    'id',
                    'external_id',
                    'damage_level',
                    'structure_type',
                    'habitability',
                    'hazards_present',
                    'urgent_needs',
                    'people_affected',
                    'review_status',
                    'reviewer_comments',
                    'notes',
                    'assessed_at',
                    'assessed_by',
                    'incident_id',
                    'lifeline_component_id',
                    'form_schema_version',
                ]);
                $row['damage_level'] = $point->damage_level instanceof DamageLevel
                    ? $point->damage_level->value
                    : (string) $point->damage_level;
                $row['habitability'] = $point->habitability instanceof Habitability
                    ? $point->habitability->value
                    : $point->habitability;
                $row['review_status'] = $point->review_status instanceof DamageReviewStatus
                    ? $point->review_status->value
                    : (string) $point->review_status;
                $row['photo_count'] = (int) $point->photos_count;
                $row['is_official'] = $point->isOfficial();
                $row['zone'] = $point->zoneKey();
                $row['review_url'] = route('disaster.damage-points.review', $point);
                $coords = GeometryReader::pointCoordinates($point->geom);
                if ($coords !== null) {
                    $row['latitude'] = $coords['latitude'];
                    $row['longitude'] = $coords['longitude'];
                }

                return $row;
            })
            ->all();

        $formSchema = DamageFormSchema::query()
            ->forOrganization($organizationId)
            ->where('is_default', true)
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Disaster/Damage', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'points' => $points,
            'store_url' => route('disaster.damage-points.store'),
            'aggregates_url' => route('disaster.damage-points.aggregates'),
            'damage_levels' => ConsoleAuth::enumValues(DamageLevel::class),
            'habitability_options' => ConsoleAuth::enumValues(Habitability::class),
            'review_statuses' => ConsoleAuth::enumValues(DamageReviewStatus::class),
            'urgent_need_options' => ['water', 'shelter', 'medical', 'food'],
            'aggregates' => $aggregation->aggregate($organizationId),
            'form_schema' => $formSchema?->schema,
            'form_schema_version' => $formSchema?->name ?? '1.0',
            'incidents' => Incident::query()
                ->forOrganization($organizationId)
                ->orderByDesc('opened_at')
                ->limit(50)
                ->get(['id', 'title', 'status', 'opened_at']),
            'lifeline_components' => LifelineComponent::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'lifeline']),
            'imagery' => IncidentImagery::query()
                ->forOrganization($organizationId)
                ->orderByDesc('id')
                ->limit(40)
                ->get(['id', 'incident_id', 'role', 'layer_id', 'label']),
            'imagery_store_template' => '/x/disaster/incidents/{incident}/imagery',
        ]);
    }

    public function assets(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $medicalFields = ['id', 'facility_id', 'name', 'bed_capacity', 'available_beds', 'trauma_level', 'generator_status', 'resource_status', 'geom'];
        $stagingFields = ['id', 'staging_id', 'name', 'max_vehicle_cap', 'resource_status', 'geom'];
        $criticalFields = [
            'id', 'site_id', 'type', 'name', 'capacity',
            'capacity_mcm', 'height_m', 'year_built', 'spillway_capacity', 'downstream_zone_layer_id',
            'geom',
        ];

        $overdueInspections = InfrastructureCondition::query()
            ->forOrganization($organizationId)
            ->overdue()
            ->with('site:id,site_id,name,type')
            ->orderBy('next_inspection_due')
            ->get()
            ->map(fn (InfrastructureCondition $row) => [
                'id' => $row->id,
                'site_id' => $row->site_id,
                'condition_grade' => $row->condition_grade?->value ?? $row->condition_grade,
                'next_inspection_due' => optional($row->next_inspection_due)?->toIso8601String(),
                'inspector' => $row->inspector,
                'maintenance_status' => $row->maintenance_status,
                'site' => $row->site ? [
                    'id' => $row->site->id,
                    'site_id' => $row->site->site_id,
                    'name' => $row->site->name,
                    'type' => $row->site->type,
                ] : null,
            ])
            ->all();

        return Inertia::render('Disaster/Assets', [
            ...AdvisoryCopy::forUi(),
            'org_map' => DisasterMapConfig::forOrganization($organizationId),
            ...ConsoleAuth::flags($request->user()),
            'medical' => MedicalFacility::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get($medicalFields)
                ->map(fn (MedicalFacility $row) => PresentsAssetRows::row($row, array_diff($medicalFields, ['geom'])))
                ->all(),
            'staging' => StagingArea::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get($stagingFields)
                ->map(fn (StagingArea $row) => PresentsAssetRows::row($row, array_diff($stagingFields, ['geom'])))
                ->all(),
            'critical' => CriticalSite::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get($criticalFields)
                ->map(fn (CriticalSite $row) => PresentsAssetRows::row($row, array_diff($criticalFields, ['geom'])))
                ->all(),
            'overdue_inspections' => $overdueInspections,
            'medical_store_url' => route('disaster.medical.store'),
            'staging_store_url' => route('disaster.staging.store'),
            'critical_store_url' => route('disaster.critical-sites.store'),
            'condition_store_url' => route('disaster.infrastructure-conditions.store'),
            'generator_statuses' => ConsoleAuth::enumValues(GeneratorStatus::class),
            'resource_statuses' => ConsoleAuth::enumValues(ResourceStatus::class),
            'critical_site_types' => ConsoleAuth::enumValues(CriticalSiteType::class),
            'condition_grades' => ConsoleAuth::enumValues(InfrastructureConditionGrade::class),
        ]);
    }

    public function planning(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $shelters = CriticalSite::query()
            ->forOrganization($organizationId)
            ->where('type', CriticalSiteType::Shelter->value)
            ->orderBy('name')
            ->get(['id', 'name', 'site_id'])
            ->all();

        return Inertia::render('Disaster/Planning', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'corridors' => EvacCorridor::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'shelter_site_id', 'source', 'distance_m', 'travel_time_s']),
            'shelters' => $shelters,
            'evac_store_url' => route('disaster.evac.store'),
            'assignments' => EvacAssignment::query()
                ->forOrganization($organizationId)
                ->with(['shelterSite:id,site_id,name,capacity'])
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
            'routing_compute_url' => route('disaster.evac-routing.compute'),
        ]);
    }
}
