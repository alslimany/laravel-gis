<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Models\InventoryItem;
use Lumina\Disaster\Models\InventoryLedger;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Support\Enums\InventoryLedgerReason;

/**
 * Sole writer of inventory quantities. All stock changes are append-only ledger rows
 * applied inside a transaction with a locked inventory row (concurrent-safe where the
 * driver supports SELECT … FOR UPDATE).
 */
final class InventoryLedgerService
{
    /**
     * @throws ValidationException
     */
    public function apply(
        Warehouse $warehouse,
        int $resourceTypeId,
        int $delta,
        InventoryLedgerReason $reason,
        ?User $actor = null,
        ?int $requestId = null,
        ?string $note = null,
        ?\DateTimeInterface $expiry = null,
    ): InventoryLedger {
        if ($delta === 0) {
            throw ValidationException::withMessages([
                'delta' => 'Ledger delta must be non-zero.',
            ]);
        }

        $this->assertReasonDelta($reason, $delta);

        return DB::transaction(function () use (
            $warehouse,
            $resourceTypeId,
            $delta,
            $reason,
            $actor,
            $requestId,
            $note,
            $expiry,
        ) {
            $item = InventoryItem::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('resource_type_id', $resourceTypeId)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                if ($delta < 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Insufficient stock: item does not exist at this warehouse.',
                    ]);
                }

                $item = InventoryItem::query()->create([
                    'organization_id' => $warehouse->organization_id,
                    'warehouse_id' => $warehouse->id,
                    'resource_type_id' => $resourceTypeId,
                    'quantity' => 0,
                    'expiry' => $expiry,
                ]);

                $item = InventoryItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            }

            $next = (int) $item->quantity + $delta;
            if ($next < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock: have {$item->quantity}, delta {$delta}.",
                ]);
            }

            $item->quantity = $next;
            if ($expiry !== null) {
                $item->expiry = $expiry;
            }
            $item->save();

            return InventoryLedger::query()->create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'resource_type_id' => $resourceTypeId,
                'delta' => $delta,
                'reason' => $reason,
                'actor_user_id' => $actor?->getKey(),
                'request_id' => $requestId,
                'note' => $note,
                'created_at' => now(),
            ]);
        });
    }

    public function stockIn(
        Warehouse $warehouse,
        int $resourceTypeId,
        int $quantity,
        ?User $actor = null,
        ?string $note = null,
        ?\DateTimeInterface $expiry = null,
    ): InventoryLedger {
        return $this->apply(
            $warehouse,
            $resourceTypeId,
            abs($quantity),
            InventoryLedgerReason::In,
            $actor,
            null,
            $note,
            $expiry,
        );
    }

    public function stockOut(
        Warehouse $warehouse,
        int $resourceTypeId,
        int $quantity,
        ?User $actor = null,
        ?int $requestId = null,
        ?string $note = null,
    ): InventoryLedger {
        return $this->apply(
            $warehouse,
            $resourceTypeId,
            -abs($quantity),
            InventoryLedgerReason::Out,
            $actor,
            $requestId,
            $note,
        );
    }

    protected function assertReasonDelta(InventoryLedgerReason $reason, int $delta): void
    {
        if ($reason === InventoryLedgerReason::In && $delta < 0) {
            throw ValidationException::withMessages([
                'delta' => 'Stock-in ledger entries require a positive delta.',
            ]);
        }

        if ($reason === InventoryLedgerReason::Out && $delta > 0) {
            throw ValidationException::withMessages([
                'delta' => 'Stock-out ledger entries require a negative delta.',
            ]);
        }
    }
}
