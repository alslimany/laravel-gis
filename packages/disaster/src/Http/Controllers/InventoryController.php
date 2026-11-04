<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\InventoryItem;
use Lumina\Disaster\Models\InventoryLedger;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Services\InventoryLedgerService;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\Enums\InventoryLedgerReason;
use Lumina\Disaster\Support\Enums\WarehouseStatus;
use Lumina\Disaster\Support\GeometryReader;
use Lumina\Disaster\Support\GeometryWriter;

class InventoryController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(protected InventoryLedgerService $ledger) {}

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize('disaster.view');

        $organizationId = (int) $request->user()->organization_id;

        $warehouses = Warehouse::query()
            ->forOrganization($organizationId)
            ->with('manager:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $w) => $this->presentWarehouse($w))
            ->all();

        $types = ResourceType::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get();

        $items = InventoryItem::query()
            ->forOrganization($organizationId)
            ->with(['warehouse', 'resourceType'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'warehouse_id' => $item->warehouse_id,
                'warehouse_name' => $item->warehouse?->name,
                'resource_type_id' => $item->resource_type_id,
                'resource_type_name' => $item->resourceType?->name,
                'unit' => $item->resourceType?->unit,
                'quantity' => $item->quantity,
                'expiry' => $item->expiry?->toIso8601String(),
            ])
            ->all();

        $ledger = InventoryLedger::query()
            ->forOrganization($organizationId)
            ->with(['warehouse', 'resourceType', 'actor:id,name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (InventoryLedger $entry) => [
                'id' => $entry->id,
                'warehouse_name' => $entry->warehouse?->name,
                'resource_type_name' => $entry->resourceType?->name,
                'delta' => $entry->delta,
                'reason' => $entry->reason instanceof InventoryLedgerReason
                    ? $entry->reason->value
                    : $entry->reason,
                'actor_name' => $entry->actor?->name,
                'request_id' => $entry->request_id,
                'note' => $entry->note,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();

        $managers = User::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json([
                'warehouses' => $warehouses,
                'resource_types' => $types,
                'items' => $items,
                'ledger' => $ledger,
            ]);
        }

        return Inertia::render('Disaster/Inventory', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'warehouses' => $warehouses,
            'resource_types' => $types,
            'items' => $items,
            'ledger' => $ledger,
            'managers' => $managers,
            'warehouse_status_options' => ConsoleAuth::enumOptions(WarehouseStatus::class),
            'ledger_reason_options' => ConsoleAuth::enumOptions(InventoryLedgerReason::class),
            'store_type_url' => route('disaster.inventory.types.store'),
            'store_warehouse_url' => route('disaster.inventory.warehouses.store'),
            'adjust_url' => route('disaster.inventory.adjust'),
        ]);
    }

    public function storeType(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'capability' => ['nullable', 'string', 'max:128'],
            'unit' => ['nullable', 'string', 'max:32'],
        ]);

        $type = ResourceType::query()->create([
            ...$data,
            'unit' => $data['unit'] ?? 'ea',
            'organization_id' => $organizationId,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $type], 201);
        }

        return redirect()->route('disaster.inventory.index')->with('success', 'Resource type created.');
    }

    public function storeWarehouse(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'manager_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'status' => ['nullable', Rule::enum(WarehouseStatus::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $warehouse = Warehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'manager_user_id' => $data['manager_user_id'] ?? null,
            'status' => $data['status'] ?? WarehouseStatus::Active,
            'geom' => isset($data['latitude'], $data['longitude'])
                ? GeometryWriter::pointFromCoordinates((float) $data['latitude'], (float) $data['longitude'])
                : null,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->presentWarehouse($warehouse->fresh('manager'))], 201);
        }

        return redirect()->route('disaster.inventory.index')->with('success', 'Warehouse created.');
    }

    public function adjust(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('dm_warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'resource_type_id' => [
                'required',
                'integer',
                Rule::exists('dm_resource_types', 'id')->where('organization_id', $organizationId),
            ],
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::enum(InventoryLedgerReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'expiry' => ['nullable', 'date'],
        ]);

        $warehouse = Warehouse::query()
            ->forOrganization($organizationId)
            ->whereKey($data['warehouse_id'])
            ->firstOrFail();

        $reason = InventoryLedgerReason::from($data['reason']);
        $delta = (int) $data['delta'];
        if ($reason === InventoryLedgerReason::In) {
            $delta = abs($delta);
        } elseif ($reason === InventoryLedgerReason::Out) {
            $delta = -abs($delta);
        }

        $entry = $this->ledger->apply(
            $warehouse,
            (int) $data['resource_type_id'],
            $delta,
            $reason,
            $request->user(),
            null,
            $data['note'] ?? null,
            isset($data['expiry']) ? new \DateTimeImmutable($data['expiry']) : null,
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $entry], 201);
        }

        return redirect()->route('disaster.inventory.index')->with('success', 'Inventory adjusted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentWarehouse(Warehouse $warehouse): array
    {
        $coords = GeometryReader::pointCoordinates($warehouse->geom);

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'status' => $warehouse->status instanceof WarehouseStatus
                ? $warehouse->status->value
                : $warehouse->status,
            'manager_user_id' => $warehouse->manager_user_id,
            'manager_name' => $warehouse->manager?->name,
            'latitude' => $coords['latitude'] ?? null,
            'longitude' => $coords['longitude'] ?? null,
            'map' => $coords !== null ? [
                'type' => 'Point',
                'coordinates' => [$coords['longitude'], $coords['latitude']],
            ] : null,
        ];
    }
}
