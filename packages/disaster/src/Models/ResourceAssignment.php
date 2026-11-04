<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class ResourceAssignment extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_resource_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'integer',
        'eta' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ResourceRequest::class, 'request_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function avlUnit(): BelongsTo
    {
        return $this->belongsTo(AvlUnit::class, 'avl_unit_id');
    }
}
