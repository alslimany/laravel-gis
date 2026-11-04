<?php

namespace Lumina\DesktopConnect\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log for grant create/revoke (DG-1.5).
 */
class GrantEvent extends Model
{
    public const ACTION_GRANTED = 'granted';

    public const ACTION_REVOKED = 'revoked';

    public $timestamps = false;

    protected $table = 'dc_grant_events';

    protected $fillable = [
        'organization_id',
        'grant_id',
        'action',
        'actor_user_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class, 'grant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
