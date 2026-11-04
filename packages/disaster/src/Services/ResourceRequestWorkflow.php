<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use App\Support\EnumLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\EventLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\ResourceAssignment;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Support\Enums\InventoryLedgerReason;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;
use Lumina\Disaster\Support\GeometryReader;

final class ResourceRequestWorkflow
{
    public function __construct(
        protected WarehouseMatcher $matcher,
        protected InventoryLedgerService $ledger,
    ) {}

    /**
     * @param  array{
     *     resource_type_id: int,
     *     quantity: int,
     *     priority?: string,
     *     needed_by?: string|null,
     *     notes?: string|null
     * }  $data
     */
    public function create(Incident $incident, User $actor, array $data): ResourceRequest
    {
        Gate::forUser($actor)->authorize('disaster.dispatch');

        $request = ResourceRequest::query()->create([
            'organization_id' => $incident->organization_id,
            'requester_user_id' => $actor->getKey(),
            'incident_id' => $incident->id,
            'resource_type_id' => $data['resource_type_id'],
            'quantity' => $data['quantity'],
            'priority' => $data['priority'] ?? 'normal',
            'needed_by' => $data['needed_by'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => ResourceRequestStatus::Requested,
        ]);

        $request->edxl_json = ResourceRequestExporter::toEdxlRmShape($request);
        $request->save();

        EventLog::record(
            $incident,
            'resource_requested',
            "Resource request #{$request->id} created",
            $actor->getKey(),
            ['request_id' => $request->id, 'quantity' => $request->quantity],
        );

        return $request->fresh(['resourceType', 'assignments', 'requester']);
    }

    public function transition(
        ResourceRequest $request,
        ResourceRequestStatus $to,
        User $actor,
    ): ResourceRequest {
        Gate::forUser($actor)->authorize('disaster.dispatch');

        $from = $request->status instanceof ResourceRequestStatus
            ? $request->status
            : ResourceRequestStatus::from((string) $request->status);

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => __('disaster.rr.cannot_transition', ['from' => EnumLabel::for($from), 'to' => EnumLabel::for($to)]),
            ]);
        }

        return DB::transaction(function () use ($request, $from, $to, $actor) {
            if ($to === ResourceRequestStatus::Dispatched) {
                $this->debitAssignedWarehouses($request, $actor);
            }

            if ($to === ResourceRequestStatus::Delivered) {
                $request->assignments()
                    ->whereNull('delivered_at')
                    ->update(['delivered_at' => now()]);
            }

            if ($to === ResourceRequestStatus::Cancelled && $from === ResourceRequestStatus::Dispatched) {
                $this->creditAssignedWarehouses($request, $actor);
            }

            $request->status = $to;
            $request->save();

            $request->load([
                'incident',
                'resourceType',
                'assignments.warehouse',
                'assignments.avlUnit',
                'requester',
            ]);
            $request->edxl_json = ResourceRequestExporter::toEdxlRmShape($request);
            $request->save();

            EventLog::record(
                $request->incident,
                'resource_request_'.$to->value,
                "Resource request #{$request->id}: {$from->value} → {$to->value}",
                $actor->getKey(),
                ['request_id' => $request->id, 'from' => $from->value, 'to' => $to->value],
            );

            return $request->fresh(['resourceType', 'assignments.warehouse', 'assignments.avlUnit', 'requester', 'incident']);
        });
    }

    /**
     * Match nearest warehouse with stock and create an assignment (approved → matched).
     *
     * @param  array{warehouse_id?: int|null, avl_unit_id?: int|null, quantity?: int|null, eta?: string|null}  $options
     */
    public function matchAndAssign(ResourceRequest $request, User $actor, array $options = []): ResourceRequest
    {
        Gate::forUser($actor)->authorize('disaster.dispatch');

        $status = $request->status instanceof ResourceRequestStatus
            ? $request->status
            : ResourceRequestStatus::from((string) $request->status);

        if ($status !== ResourceRequestStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => __('disaster.rr.only_approved_match'),
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $options) {
            $quantity = (int) ($options['quantity'] ?? $request->quantity);
            $warehouseId = isset($options['warehouse_id']) ? (int) $options['warehouse_id'] : null;
            $avlUnitId = isset($options['avl_unit_id']) ? (int) $options['avl_unit_id'] : null;

            if ($warehouseId === null && $avlUnitId === null) {
                $request->loadMissing('incident');
                $coords = GeometryReader::pointCoordinates($request->incident?->geom);
                $match = $this->matcher->bestMatch(
                    (int) $request->organization_id,
                    (int) $request->resource_type_id,
                    $quantity,
                    $coords['latitude'] ?? null,
                    $coords['longitude'] ?? null,
                );

                if ($match === null) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => __('disaster.rr.no_stock'),
                    ]);
                }

                $warehouseId = (int) $match['warehouse']->id;
            }

            if ($warehouseId !== null) {
                $warehouse = Warehouse::query()
                    ->where('organization_id', $request->organization_id)
                    ->whereKey($warehouseId)
                    ->firstOrFail();

                $available = (int) $warehouse->inventoryItems()
                    ->where('resource_type_id', $request->resource_type_id)
                    ->value('quantity');

                if ($available < $quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => __('disaster.rr.only_available', ['count' => $available]),
                    ]);
                }
            }

            if ($warehouseId === null && $avlUnitId === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => __('disaster.rr.needs_assignee'),
                ]);
            }

            ResourceAssignment::query()->create([
                'organization_id' => $request->organization_id,
                'request_id' => $request->id,
                'warehouse_id' => $warehouseId,
                'avl_unit_id' => $avlUnitId,
                'quantity' => $quantity,
                'eta' => $options['eta'] ?? null,
            ]);

            return $this->transition($request->fresh(), ResourceRequestStatus::Matched, $actor);
        });
    }

    /**
     * Suggest matches without mutating state.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestMatches(ResourceRequest $request, int $limit = 5): array
    {
        $request->loadMissing('incident');
        $coords = GeometryReader::pointCoordinates($request->incident?->geom);

        return array_map(function (array $row) {
            return [
                'warehouse_id' => $row['warehouse']->id,
                'warehouse_name' => $row['warehouse']->name,
                'quantity_available' => $row['quantity_available'],
                'distance_m' => $row['distance_m'],
            ];
        }, $this->matcher->nearestWithStock(
            (int) $request->organization_id,
            (int) $request->resource_type_id,
            (int) $request->quantity,
            $coords['latitude'] ?? null,
            $coords['longitude'] ?? null,
            $limit,
        ));
    }

    protected function debitAssignedWarehouses(ResourceRequest $request, User $actor): void
    {
        $assignments = $request->assignments()->whereNotNull('warehouse_id')->get();
        if ($assignments->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('disaster.rr.dispatch_needs_warehouse'),
            ]);
        }

        foreach ($assignments as $assignment) {
            $warehouse = Warehouse::query()->findOrFail($assignment->warehouse_id);
            $this->ledger->stockOut(
                $warehouse,
                (int) $request->resource_type_id,
                (int) $assignment->quantity,
                $actor,
                (int) $request->id,
                "Dispatch request #{$request->id}",
            );
        }
    }

    protected function creditAssignedWarehouses(ResourceRequest $request, User $actor): void
    {
        foreach ($request->assignments()->whereNotNull('warehouse_id')->get() as $assignment) {
            $warehouse = Warehouse::query()->findOrFail($assignment->warehouse_id);
            $this->ledger->apply(
                $warehouse,
                (int) $request->resource_type_id,
                (int) $assignment->quantity,
                InventoryLedgerReason::In,
                $actor,
                (int) $request->id,
                "Cancel dispatch request #{$request->id}",
            );
        }
    }
}
