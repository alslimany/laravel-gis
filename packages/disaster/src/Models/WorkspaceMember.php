<?php

namespace Lumina\Disaster\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Support\Enums\WorkspaceMemberRole;

class WorkspaceMember extends Model
{
    protected $table = 'dm_workspace_members';

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'viewer',
    ];

    protected $casts = [
        'role' => WorkspaceMemberRole::class,
        'scope' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(IncidentWorkspace::class, 'workspace_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * @return list<int>
     */
    public function sharedLayerIds(): array
    {
        $scope = $this->scope ?? [];
        $ids = $scope['layer_ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
