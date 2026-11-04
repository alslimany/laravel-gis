<?php

namespace Lumina\DesktopConnect\Policies;

use App\Models\User;
use Lumina\DesktopConnect\Models\ApiKey;

/**
 * Org members mint their own desktop keys; owners or org admins revoke (DG-1.6).
 */
class ApiKeyPolicy
{
    public function create(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function delete(User $user, ApiKey $key): bool
    {
        if ($user->organization_id === null) {
            return false;
        }

        if ((int) $user->organization_id !== (int) $key->organization_id) {
            return false;
        }

        return (int) $user->id === (int) $key->user_id || $user->hasRole('admin');
    }
}
