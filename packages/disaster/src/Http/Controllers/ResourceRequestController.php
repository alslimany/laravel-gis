<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Services\ResourceRequestWorkflow;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\Enums\ResourceRequestPriority;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;

class ResourceRequestController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(protected ResourceRequestWorkflow $workflow) {}

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize('disaster.view');

        $organizationId = (int) $request->user()->organization_id;
        $requests = ResourceRequest::query()
            ->forOrganization($organizationId)
            ->with(['resourceType', 'incident', 'requester:id,name', 'assignments.warehouse'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ResourceRequest $row) => $this->present($row))
            ->all();

        $incidents = Incident::query()
            ->forOrganization($organizationId)
            ->where('status', '!=', 'closed')
            ->orderByDesc('opened_at')
            ->get(['id', 'external_id', 'title', 'status']);

        $types = ResourceType::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'unit']);

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['data' => $requests]);
        }

        return Inertia::render('Disaster/ResourceRequests', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'requests' => $requests,
            'incidents' => $incidents,
            'resource_types' => $types,
            'status_options' => ConsoleAuth::enumOptions(ResourceRequestStatus::class),
            'priority_options' => ConsoleAuth::enumOptions(ResourceRequestPriority::class),
            'store_url' => route('disaster.resource-requests.store'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.dispatch');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'incident_id' => [
                'required',
                'integer',
                Rule::exists('dm_incidents', 'id')->where('organization_id', $organizationId),
            ],
            'resource_type_id' => [
                'required',
                'integer',
                Rule::exists('dm_resource_types', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', Rule::enum(ResourceRequestPriority::class)],
            'needed_by' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $incident = Incident::query()
            ->forOrganization($organizationId)
            ->whereKey($data['incident_id'])
            ->firstOrFail();

        $created = $this->workflow->create($incident, $request->user(), $data);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($created)], 201);
        }

        return redirect()
            ->route('disaster.resource-requests.index')
            ->with('success', 'Resource request created.');
    }

    public function transition(Request $request, ResourceRequest $resourceRequest): JsonResponse|RedirectResponse
    {
        $this->assertOrg($request, $resourceRequest);

        $data = $request->validate([
            'status' => ['required', Rule::enum(ResourceRequestStatus::class)],
        ]);

        $updated = $this->workflow->transition(
            $resourceRequest,
            ResourceRequestStatus::from($data['status']),
            $request->user(),
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($updated)]);
        }

        return redirect()
            ->route('disaster.resource-requests.index')
            ->with('success', 'Request status updated.');
    }

    public function match(Request $request, ResourceRequest $resourceRequest): JsonResponse|RedirectResponse
    {
        $this->assertOrg($request, $resourceRequest);

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'avl_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_avl_units', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'eta' => ['nullable', 'date'],
        ]);

        $updated = $this->workflow->matchAndAssign($resourceRequest, $request->user(), $data);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($updated)]);
        }

        return redirect()
            ->route('disaster.resource-requests.index')
            ->with('success', 'Request matched and assigned.');
    }

    public function suggestions(Request $request, ResourceRequest $resourceRequest): JsonResponse
    {
        Gate::authorize('disaster.view');
        $this->assertOrg($request, $resourceRequest);

        return response()->json([
            'data' => $this->workflow->suggestMatches($resourceRequest),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(ResourceRequest $row): array
    {
        $status = $row->status instanceof ResourceRequestStatus
            ? $row->status
            : ResourceRequestStatus::from((string) $row->status);

        return [
            'id' => $row->id,
            'incident_id' => $row->incident_id,
            'incident_title' => $row->incident?->title,
            'incident_external_id' => $row->incident?->external_id,
            'incident_url' => $row->incident_id
                ? route('disaster.incidents.show', $row->incident_id)
                : null,
            'resource_type_id' => $row->resource_type_id,
            'resource_type_name' => $row->resourceType?->name,
            'resource_type_unit' => $row->resourceType?->unit,
            'quantity' => $row->quantity,
            'priority' => $row->priority instanceof \BackedEnum ? $row->priority->value : $row->priority,
            'needed_by' => $row->needed_by?->toIso8601String(),
            'status' => $status->value,
            'notes' => $row->notes,
            'requester_name' => $row->requester?->name,
            'assignments' => $row->assignments->map(fn ($a) => [
                'id' => $a->id,
                'warehouse_id' => $a->warehouse_id,
                'warehouse_name' => $a->warehouse?->name,
                'avl_unit_id' => $a->avl_unit_id,
                'quantity' => $a->quantity,
                'eta' => $a->eta?->toIso8601String(),
                'delivered_at' => $a->delivered_at?->toIso8601String(),
            ])->values()->all(),
            'allowed_transitions' => array_map(
                fn (ResourceRequestStatus $s) => $s->value,
                $status->allowedTransitions(),
            ),
            'transition_url' => route('disaster.resource-requests.transition', $row),
            'match_url' => route('disaster.resource-requests.match', $row),
            'suggestions_url' => route('disaster.resource-requests.suggestions', $row),
            'edxl_json' => $row->edxl_json,
        ];
    }

    protected function assertOrg(Request $request, ResourceRequest $resourceRequest): void
    {
        abort_unless(
            (int) $resourceRequest->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
