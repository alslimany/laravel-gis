<?php

namespace App\Policies;

use App\Models\Layer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LayerPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Layer $layer): bool
    {
        return $user->organization_id === $layer->organization_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->organization_id !== null &&
               $user->hasAnyRole(['admin', 'editor']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Layer $layer): bool
    {
        return $user->organization_id === $layer->organization_id &&
               $user->hasAnyRole(['admin', 'editor']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Layer $layer): bool
    {
        return $user->organization_id === $layer->organization_id &&
               $user->hasRole('admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Layer $layer): bool
    {
        return $user->organization_id === $layer->organization_id &&
               $user->hasRole('admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Layer $layer): bool
    {
        return $user->organization_id === $layer->organization_id &&
               $user->hasRole('admin');
    }
}
