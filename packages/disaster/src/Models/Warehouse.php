<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\WarehouseStatus;

class Warehouse extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_warehouses';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'status' => WarehouseStatus::class,
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedger::class);
    }
}
