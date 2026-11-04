<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class ResourceType extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_resource_types';

    protected $guarded = ['id'];

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'resource_type_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ResourceRequest::class, 'resource_type_id');
    }
}
