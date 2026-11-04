<?php

namespace Lumina\DesktopConnect\Models;

use App\Models\Group;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grant extends Model
{
    public const SCOPE_READ = 'read';

    public const SCOPE_EDIT = 'edit';

    public const SCOPES = [self::SCOPE_READ, self::SCOPE_EDIT];

    protected $table = 'dc_grants';

    protected $fillable = [
        'organization_id',
        'user_id',
        'group_id',
        'layer_id',
        'scope',
        'expires_at',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GrantEvent::class, 'grant_id');
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lte($at ?? now());
    }

    public function isActive(?\DateTimeInterface $at = null): bool
    {
        return ! $this->isExpired($at);
    }

    /**
     * @return array<string, mixed>
     */
    public function toEventPayload(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'group_id' => $this->group_id,
            'layer_id' => $this->layer_id,
            'scope' => $this->scope,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'granted_by' => $this->granted_by,
        ];
    }
}
