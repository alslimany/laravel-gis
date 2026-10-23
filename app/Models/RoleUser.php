<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for role grants. Each grant is scoped to an organization (#66 / D2).
 */
class RoleUser extends Pivot
{
    protected $table = 'role_user';

    public $incrementing = true;

    protected static function booted(): void
    {
        // When no organization is given explicitly, scope the grant to the
        // user's current organization (null stays null for org-less users).
        static::creating(function (RoleUser $pivot) {
            if (! array_key_exists('organization_id', $pivot->getAttributes())) {
                $pivot->organization_id = User::query()
                    ->whereKey($pivot->user_id)
                    ->value('organization_id');
            }
        });
    }
}
