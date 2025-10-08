<?php

namespace App\Policies;

use App\Models\DataImport;
use App\Models\User;

class DataImportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DataImport $dataImport): bool
    {
        return $user->id === $dataImport->user_id ||
               $user->organization_id === $dataImport->organization_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->organization_id !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DataImport $dataImport): bool
    {
        return $user->id === $dataImport->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DataImport $dataImport): bool
    {
        return $user->id === $dataImport->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DataImport $dataImport): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DataImport $dataImport): bool
    {
        return false;
    }
}
