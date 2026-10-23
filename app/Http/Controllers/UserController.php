<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        // Always scope to the admin's own organization (the 'organization'
        // middleware guarantees it is non-null). Previously a null-org admin
        // skipped this filter and saw every user (#66).
        $users = User::with(['organization', 'roles'])
            ->where('organization_id', $request->user()->organization_id)
            ->paginate(15);

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        $this->authorize('update', $user);

        $roles = Role::all();
        $user->load(['roles', 'organization']);

        return Inertia::render('Users/Edit', [
            'user' => $user,
            'roles' => $roles,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'roles' => 'array',
            'roles.*' => [
                'integer',
                Rule::in(Role::whereIn('name', User::ASSIGNABLE_ROLES)->pluck('id')->all()),
            ],
        ]);

        if (isset($validated['roles'])) {
            $this->guardAdminRemoval($request->user(), $user, $validated['roles']);
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if (isset($validated['roles'])) {
            // Grants made here are scoped to the managed user's organization,
            // which the policy has already checked equals the admin's.
            // withPivotValue limits the sync to this org's grants and stamps
            // new grants with it, so grants in other orgs are left alone.
            $user->roles()
                ->withPivotValue('organization_id', $user->organization_id)
                ->sync($validated['roles']);
        }

        return redirect()->route('users.index')
            ->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'User deleted successfully.');
    }

    /**
     * An admin may not drop their own admin role, and an org must keep at
     * least one admin (#66).
     *
     * @param  array<int, int|string>  $roleIds
     */
    protected function guardAdminRemoval(User $actor, User $user, array $roleIds): void
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');

        if ($adminRoleId === null
            || in_array((int) $adminRoleId, array_map('intval', $roleIds), true)
            || ! $user->hasRole('admin')) {
            return;
        }

        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'roles' => 'You cannot remove your own admin role.',
            ]);
        }

        $otherAdmins = User::query()
            ->where('organization_id', $user->organization_id)
            ->whereKeyNot($user->getKey())
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.id', $adminRoleId)
                ->where('role_user.organization_id', $user->organization_id))
            ->exists();

        if (! $otherAdmins) {
            throw ValidationException::withMessages([
                'roles' => 'An organization must keep at least one admin.',
            ]);
        }
    }
}
