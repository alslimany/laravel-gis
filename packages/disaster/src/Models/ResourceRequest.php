<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\ResourceRequestPriority;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;

class ResourceRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_resource_requests';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'integer',
        'priority' => ResourceRequestPriority::class,
        'status' => ResourceRequestStatus::class,
        'needed_by' => 'datetime',
        'edxl_json' => 'array',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ResourceAssignment::class, 'request_id');
    }
}
