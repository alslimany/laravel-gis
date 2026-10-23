<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null && $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $this->sameOrganization($user, $model) &&
               $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->organization_id !== null && $user->hasRole('admin');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $this->sameOrganization($user, $model) &&
               $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->sameOrganization($user, $model) &&
               $user->hasRole('admin') &&
               $user->id !== $model->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $this->sameOrganization($user, $model) &&
               $user->hasRole('admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $this->sameOrganization($user, $model) &&
               $user->hasRole('admin') &&
               $user->id !== $model->id;
    }

    /**
     * Admins are organization admins, never global: both sides must belong
     * to the same, non-null organization (#66).
     */
    protected function sameOrganization(User $user, User $model): bool
    {
        return $user->organization_id !== null
            && (int) $user->organization_id === (int) $model->organization_id;
    }
}
