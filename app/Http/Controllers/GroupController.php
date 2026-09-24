<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GroupController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'organization']);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Group::class);

        $groups = Group::query()
            ->where('organization_id', $request->user()->organization_id)
            ->withCount('users')
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('Groups/Index', [
            'groups' => $groups,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Group::class);

        return Inertia::render('Groups/Form');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Group::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $group = Group::create([
            ...$validated,
            'organization_id' => $request->user()->organization_id,
        ]);

        return redirect()->route('groups.show', $group)
            ->with('success', 'Group created successfully.');
    }

    public function show(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        $group->load(['users' => fn ($q) => $q->orderBy('name')]);

        $availableUsers = User::query()
            ->where('organization_id', $group->organization_id)
            ->whereNotIn('id', $group->users->pluck('id'))
            ->orderBy('name')
            ->get();

        return Inertia::render('Groups/Show', [
            'group' => $group,
            'availableUsers' => $availableUsers,
            'canManageMembers' => $request->user()->can('manageMembers', $group),
        ]);
    }

    public function edit(Group $group)
    {
        $this->authorize('update', $group);

        return Inertia::render('Groups/Form', [
            'group' => $group,
        ]);
    }

    public function update(Request $request, Group $group)
    {
        $this->authorize('update', $group);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $group->update($validated);

        return redirect()->route('groups.show', $group)
            ->with('success', 'Group updated successfully.');
    }

    public function destroy(Group $group)
    {
        $this->authorize('delete', $group);

        $group->delete();

        return redirect()->route('groups.index')
            ->with('success', 'Group deleted successfully.');
    }

    public function attachUser(Request $request, Group $group)
    {
        $this->authorize('manageMembers', $group);

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $group->organization_id),
            ],
            'role' => 'required|in:member,admin',
        ]);

        if ($group->users()->where('users.id', $validated['user_id'])->exists()) {
            return back()->with('error', 'User is already a member of this group.');
        }

        $group->users()->attach($validated['user_id'], ['role' => $validated['role']]);

        return back()->with('success', 'User added to group.');
    }

    public function detachUser(Group $group, User $user)
    {
        $this->authorize('manageMembers', $group);

        if ($user->organization_id !== $group->organization_id) {
            abort(404);
        }

        $group->users()->detach($user->id);

        return back()->with('success', 'User removed from group.');
    }

    public function updateUserRole(Request $request, Group $group, User $user)
    {
        $this->authorize('manageMembers', $group);

        if ($user->organization_id !== $group->organization_id) {
            abort(404);
        }

        $validated = $request->validate([
            'role' => 'required|in:member,admin',
        ]);

        if (! $group->users()->where('users.id', $user->id)->exists()) {
            return back()->with('error', 'User is not a member of this group.');
        }

        $group->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return back()->with('success', 'Member role updated.');
    }
}
