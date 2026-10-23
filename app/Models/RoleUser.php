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
        static::creating(function (RoleUser $pivot) {
            // Use the already-loaded parent user (no extra query). Grants made
            // from the Role side must pass organization_id explicitly.
            if (! array_key_exists('organization_id', $pivot->getAttributes())
                && $pivot->pivotParent instanceof User) {
                $pivot->organization_id = $pivot->pivotParent->organization_id;
            }
        });
    }
}
