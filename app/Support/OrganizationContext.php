<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * GIS Core → plugin helper: current organization and role checks (Project Guide §6).
 * Prefer this over ad-hoc Auth::user()->organization_id in plugin code.
 */
final class OrganizationContext
{
    public function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function organizationId(): ?int
    {
        $id = $this->user()?->organization_id;

        return $id !== null ? (int) $id : null;
    }

    public function organization(): ?Organization
    {
        return $this->user()?->organization;
    }

    /**
     * Require a non-null organization id (HTTP plugin routes after `organization` middleware).
     */
    public function requireOrganizationId(): int
    {
        $id = $this->organizationId();
        if ($id === null) {
            abort(403, 'Organization required.');
        }

        return $id;
    }

    public function isOrgAdmin(?User $user = null): bool
    {
        $user ??= $this->user();
        if ($user === null || $user->organization_id === null) {
            return false;
        }

        return $user->hasRole('admin');
    }
}
