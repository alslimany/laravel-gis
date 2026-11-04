<?php

namespace Lumina\DesktopConnect\Policies;

use App\Models\User;
use Lumina\DesktopConnect\Models\Grant;

/**
 * Org members may list grants; only org admins manage; cross-org denied (DG-1.5).
 */
class GrantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function view(User $user, Grant $grant): bool
    {
        return $user->organization_id !== null
            && (int) $user->organization_id === (int) $grant->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->isOrgAdmin($user);
    }

    public function delete(User $user, Grant $grant): bool
    {
        return $this->sameOrgAdmin($user, $grant);
    }

    protected function isOrgAdmin(User $user): bool
    {
        return $user->organization_id !== null && $user->hasRole('admin');
    }

    protected function sameOrgAdmin(User $user, Grant $grant): bool
    {
        if (! $this->isOrgAdmin($user)) {
            return false;
        }

        return (int) $user->organization_id === (int) $grant->organization_id;
    }
}
