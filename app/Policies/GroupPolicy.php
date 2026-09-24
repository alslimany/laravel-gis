<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function view(User $user, Group $group): bool
    {
        return $user->organization_id === $group->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->organization_id !== null && $user->hasRole('admin');
    }

    public function update(User $user, Group $group): bool
    {
        return $user->organization_id === $group->organization_id
            && $user->hasRole('admin');
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->organization_id === $group->organization_id
            && $user->hasRole('admin');
    }

    public function manageMembers(User $user, Group $group): bool
    {
        if ($user->organization_id !== $group->organization_id) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $group->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }
}
