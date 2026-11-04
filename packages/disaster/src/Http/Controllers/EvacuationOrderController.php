<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Services\EvacuationOrderWorkflow;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Support\GeometryReader;
use Lumina\Disaster\Support\GeometryWriter;

class EvacuationOrderController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(protected EvacuationOrderWorkflow $workflow) {}

    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $orders = EvacuationOrder::query()
            ->forOrganization($organizationId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (EvacuationOrder $order) => $this->present($order))
            ->all();

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['data' => $orders]);
        }

        $corridors = EvacCorridor::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $shelters = CriticalSite::query()
            ->forOrganization($organizationId)
            ->where('type', CriticalSiteType::Shelter->value)
            ->orderBy('name')
            ->get(['id', 'site_id', 'name', 'capacity', 'remaining_capacity']);

        $activations = Activation::query()
            ->forOrganization($organizationId)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'hazard_type', 'source_alert_id', 'status']);

        return Inertia::render('Disaster/EvacuationOrders', [
            ...AdvisoryCopy::forUi(organizationId: $organizationId),
            ...ConsoleAuth::flags($request->user()),
            'orders' => $orders,
            'corridors' => $corridors,
            'shelters' => $shelters,
            'activations' => $activations,
            'status_options' => ConsoleAuth::enumValues(EvacuationOrderStatus::class),
            'store_url' => route('disaster.evacuation-orders.store'),
        ]);
    }

    public function show(Request $request, EvacuationOrder $evacuationOrder): Response|JsonResponse
    {
        $this->assertOrg($request, $evacuationOrder);
        $evacuationOrder->load('events');

        $row = $this->present($evacuationOrder, withEvents: true);

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['data' => $row]);
        }

        return Inertia::render('Disaster/EvacuationOrderShow', [
            ...AdvisoryCopy::forUi(
                organizationId: (int) $evacuationOrder->organization_id,
                isOfficialContent: (bool) $evacuationOrder->is_official,
            ),
            ...ConsoleAuth::flags($request->user()),
            'order' => $row,
            'index_url' => route('disaster.evacuation-orders.index'),
            'transition_url' => route('disaster.evacuation-orders.transition', $evacuationOrder),
            'execution_url' => route('disaster.evacuation-orders.execution', $evacuationOrder),
            'update_url' => route('disaster.evacuation-orders.update', $evacuationOrder),
        ]);
    }

    /**
     * The create form is part of the index page; send /create there with the form open.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('disaster.evacuation-orders.index', ['new' => 1]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(
            Gate::any(['disaster.manage', 'disaster.dispatch']),
            403,
        );

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request, $organizationId);
        $geojson = $this->polygonFromRequest($data);
        unset($data['geojson'], $data['geojson_text']);

        $order = EvacuationOrder::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'status' => EvacuationOrderStatus::Draft,
            'authored_by' => $request->user()->getKey(),
            'created_by' => $request->user()->getKey(),
            'updated_by' => $request->user()->getKey(),
            'zone_geom' => $geojson !== null ? GeometryWriter::polygonFromGeoJson($geojson) : null,
        ]);

        $order->events()->create([
            'organization_id' => $organizationId,
            'from_status' => null,
            'to_status' => EvacuationOrderStatus::Draft->value,
            'action' => 'created',
            'user_id' => $request->user()->getKey(),
            'created_at' => now(),
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($order->fresh())], 201);
        }

        return redirect()
            ->route('disaster.evacuation-orders.show', $order)
            ->with('success', __('disaster.evac.flash_drafted'));
    }

    public function update(Request $request, EvacuationOrder $evacuationOrder): JsonResponse|RedirectResponse
    {
        abort_unless(
            Gate::any(['disaster.manage', 'disaster.dispatch']),
            403,
        );
        $this->assertOrg($request, $evacuationOrder);

        $status = $evacuationOrder->status instanceof EvacuationOrderStatus
            ? $evacuationOrder->status
            : EvacuationOrderStatus::from((string) $evacuationOrder->status);

        abort_unless(
            in_array($status, [EvacuationOrderStatus::Draft, EvacuationOrderStatus::PendingApproval], true),
            422,
            __('disaster.evac.only_draft_editable'),
        );

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request, $organizationId, updating: true);
        $textAr = array_key_exists('text_ar', $data) ? $data['text_ar'] : $evacuationOrder->text_ar;
        $textEn = array_key_exists('text_en', $data) ? $data['text_en'] : $evacuationOrder->text_en;
        if (trim((string) $textAr) === '' && trim((string) $textEn) === '') {
            throw ValidationException::withMessages([
                'text_ar' => __('disaster.evac.text_required'),
            ]);
        }
        $geojson = $this->polygonFromRequest($data);
        unset($data['geojson'], $data['geojson_text']);

        $evacuationOrder->fill($data);
        if ($geojson !== null) {
            $evacuationOrder->zone_geom = GeometryWriter::polygonFromGeoJson($geojson);
        }
        $evacuationOrder->updated_by = $request->user()->getKey();
        $evacuationOrder->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($evacuationOrder->fresh())]);
        }

        return redirect()
            ->route('disaster.evacuation-orders.show', $evacuationOrder)
            ->with('success', __('disaster.evac.flash_updated'));
    }

    public function destroy(Request $request, EvacuationOrder $evacuationOrder): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $evacuationOrder);

        $status = $evacuationOrder->status instanceof EvacuationOrderStatus
            ? $evacuationOrder->status
            : EvacuationOrderStatus::from((string) $evacuationOrder->status);

        abort_unless(
            in_array($status, [EvacuationOrderStatus::Draft, EvacuationOrderStatus::Cancelled], true),
            422,
            'Only draft or cancelled orders can be deleted.',
        );

        $evacuationOrder->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()
            ->route('disaster.evacuation-orders.index')
            ->with('success', __('disaster.evac.flash_deleted'));
    }

    public function transition(Request $request, EvacuationOrder $evacuationOrder): JsonResponse|RedirectResponse
    {
        $this->assertOrg($request, $evacuationOrder);

        $data = $request->validate([
            'status' => ['required', Rule::enum(EvacuationOrderStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = EvacuationOrderStatus::from($data['status']);
        $action = match ($to) {
            EvacuationOrderStatus::PendingApproval => 'submit_for_approval',
            EvacuationOrderStatus::Approved => 'approve',
            EvacuationOrderStatus::Draft => 'return_to_draft',
            EvacuationOrderStatus::Issued => 'issue',
            EvacuationOrderStatus::InProgress => 'start_execution',
            EvacuationOrderStatus::Completed => 'complete',
            EvacuationOrderStatus::Cancelled => 'cancel',
        };

        $this->workflow->transition(
            $evacuationOrder,
            $to,
            $request->user(),
            $action,
            array_filter(['note' => $data['note'] ?? null]),
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($evacuationOrder->fresh()->load('events'), withEvents: true)]);
        }

        return redirect()
            ->route('disaster.evacuation-orders.show', $evacuationOrder)
            ->with('success', __('disaster.evac.flash_transitioned'));
    }

    public function updateExecution(Request $request, EvacuationOrder $evacuationOrder): JsonResponse|RedirectResponse
    {
        $this->assertOrg($request, $evacuationOrder);

        $data = $request->validate([
            'evacuated_count' => ['nullable', 'integer', 'min:0'],
            'unaccounted_count' => ['nullable', 'integer', 'min:0'],
            'estimated_population' => ['nullable', 'integer', 'min:0'],
            'shelter_remaining' => ['nullable', 'array'],
            'shelter_remaining.*' => ['integer', 'min:0'],
        ]);

        $this->workflow->updateExecution(
            $evacuationOrder,
            $request->user(),
            evacuatedCount: $data['evacuated_count'] ?? null,
            unaccountedCount: $data['unaccounted_count'] ?? null,
            estimatedPopulation: $data['estimated_population'] ?? null,
            shelterRemaining: $data['shelter_remaining'] ?? [],
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($evacuationOrder->fresh())]);
        }

        return redirect()
            ->route('disaster.evacuation-orders.show', $evacuationOrder)
            ->with('success', __('disaster.evac.flash_counters'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, int $organizationId, bool $updating = false): array
    {
        $data = $request->validate([
            'activation_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_activations', 'id')->where('organization_id', $organizationId),
            ],
            'layer_feature_ids' => ['nullable', 'array'],
            'layer_feature_ids.*' => ['integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_official' => ['sometimes', 'boolean'],
            // EVAC-1: one language is enough. Arabic-only orders are normal; English is optional
            // whenever Arabic is given (and vice versa).
            'text_ar' => [$updating ? 'sometimes' : 'required_without:text_en', 'nullable', 'string', 'max:20000'],
            // Only text_ar carries the "one text is required" error, so it is shown once and never names the English field.
            'text_en' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'estimated_population' => ['nullable', 'integer', 'min:0'],
            'corridor_ids' => ['nullable', 'array'],
            'corridor_ids.*' => [
                'integer',
                Rule::exists('dm_evac_corridors', 'id')->where('organization_id', $organizationId),
            ],
            'shelter_site_ids' => ['nullable', 'array'],
            'shelter_site_ids.*' => [
                'integer',
                Rule::exists('dm_critical_sites', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('type', CriticalSiteType::Shelter->value),
            ],
            'geojson' => ['nullable', 'array'],
            'geojson_text' => ['nullable', 'string', 'max:100000'],
        ], [
            'text_ar.required_without' => __('disaster.evac.text_required'),
        ]);

        if (array_key_exists('is_official', $data)) {
            $data['is_official'] = (bool) $data['is_official'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function polygonFromRequest(array $data): ?array
    {
        if (isset($data['geojson']) && is_array($data['geojson'])) {
            $type = $data['geojson']['type'] ?? '';
            abort_unless(in_array($type, ['Polygon', 'MultiPolygon'], true), 422, __('disaster.evac.zone_must_be_polygon'));

            return $data['geojson'];
        }

        $text = trim((string) ($data['geojson_text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded) || ! in_array($decoded['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
            abort(422, __('disaster.evac.zone_must_be_polygon'));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(EvacuationOrder $order, bool $withEvents = false): array
    {
        $status = $order->status instanceof EvacuationOrderStatus
            ? $order->status
            : EvacuationOrderStatus::from((string) $order->status);

        $rawZone = $order->getAttributes()['zone_geom']
            ?? ($order->exists
                ? DB::table('dm_evacuation_orders')->where('id', $order->id)->value('zone_geom')
                : null);
        $zone = GeometryReader::asGeoJson($rawZone);

        $payload = [
            'id' => $order->id,
            'activation_id' => $order->activation_id,
            'status' => $status->value,
            'allowed_transitions' => array_map(
                static fn (EvacuationOrderStatus $case) => $case->value,
                $status->allowedTransitions(),
            ),
            'authored_by' => $order->authored_by,
            'approved_by' => $order->approved_by,
            'issued_at' => $order->issued_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'is_official' => (bool) $order->is_official,
            'text_en' => $order->text_en,
            'text_ar' => $order->text_ar,
            'estimated_population' => $order->estimated_population,
            'evacuated_count' => $order->evacuated_count,
            'unaccounted_count' => $order->unaccounted_count,
            'corridor_ids' => $order->corridor_ids ?? [],
            'shelter_site_ids' => $order->shelter_site_ids ?? [],
            'layer_feature_ids' => $order->layer_feature_ids ?? [],
            'zone' => $zone,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'show_url' => route('disaster.evacuation-orders.show', $order),
        ];

        if ($withEvents) {
            $payload['events'] = $order->events
                ->map(static fn ($event) => [
                    'id' => $event->id,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'action' => $event->action,
                    'user_id' => $event->user_id,
                    'meta' => $event->meta,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all();
        }

        return $payload;
    }

    protected function assertOrg(Request $request, EvacuationOrder $order): void
    {
        abort_unless((int) $order->organization_id === (int) $request->user()->organization_id, 404);
    }
}
