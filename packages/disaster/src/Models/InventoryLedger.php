<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\InventoryLedgerReason;

/**
 * Append-only stock movement log. Quantity changes must go through InventoryLedgerService.
 */
class InventoryLedger extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_inventory_ledger';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'delta' => 'integer',
        'reason' => InventoryLedgerReason::class,
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('dm_inventory_ledger is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('dm_inventory_ledger is append-only and cannot be deleted.');
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ResourceRequest::class, 'request_id');
    }
}
