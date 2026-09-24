<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;

class WebhookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null && $user->hasRole('admin');
    }

    public function view(User $user, Webhook $webhook): bool
    {
        return $user->organization_id === $webhook->organization_id
            && $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->organization_id !== null && $user->hasRole('admin');
    }

    public function update(User $user, Webhook $webhook): bool
    {
        return $user->organization_id === $webhook->organization_id
            && $user->hasRole('admin');
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        return $user->organization_id === $webhook->organization_id
            && $user->hasRole('admin');
    }
}
