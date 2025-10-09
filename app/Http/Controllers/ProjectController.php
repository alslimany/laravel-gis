<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    /**
     * Display a listing of the projects
     */
    public function index()
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::where('organization_id', Auth::user()->organization_id)
            ->with(['user', 'layers'])
            ->withCount('collaborators')
            ->latest()
            ->paginate(15);

        return view('projects.index', compact('projects'));
    }

    /**
     * Show the form for creating a new project
     */
    public function create()
    {
        $this->authorize('create', Project::class);

        return view('projects.create');
    }

    /**
     * Store a newly created project
     */
    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean'
        ]);

        $validated['user_id'] = Auth::id();
        $validated['organization_id'] = Auth::user()->organization_id;

        $project = Project::create($validated);

        // Log activity
        $project->logActivity('created', 'Project created');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'project' => $project
            ]);
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project created successfully!');
    }

    /**
     * Display the specified project
     */
    public function show(Project $project)
    {
        // Check access
        if (!$project->userCanAccess(Auth::user()) && !$project->is_public) {
            abort(403);
        }

        $project->load(['user', 'layers', 'collaborators', 'activities' => function ($query) {
            $query->with('user')->latest()->limit(10);
        }]);

        return view('projects.show', compact('project'));
    }

    /**
     * Show the form for editing the specified project
     */
    public function edit(Project $project)
    {
        $this->authorize('update', $project);

        return view('projects.edit', compact('project'));
    }

    /**
     * Update the specified project
     */
    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean'
        ]);

        $project->update($validated);

        // Log activity
        $project->logActivity('updated', 'Project details updated');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'project' => $project
            ]);
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project updated successfully!');
    }

    /**
     * Remove the specified project
     */
    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', 'Project deleted successfully!');
    }

    /**
     * Show sharing settings for a project
     */
    public function share(Project $project)
    {
        $this->authorize('update', $project);

        return view('projects.share', compact('project'));
    }

    /**
     * View a shared project via token
     */
    public function viewShared($token)
    {
        $project = Project::where('share_token', $token)
            ->where('is_public', true)
            ->with(['layers', 'user'])
            ->firstOrFail();

        return view('projects.shared', compact('project'));
    }

    /**
     * Show the invitation form
     */
    public function invite(Project $project)
    {
        $this->authorize('update', $project);

        $availableUsers = User::where('organization_id', $project->organization_id)
            ->whereNotIn('id', $project->collaborators->pluck('id'))
            ->where('id', '!=', $project->user_id)
            ->orderBy('name')
            ->get();

        $collaborators = $project->collaborators()->withPivot('role')->get();

        return view('projects.invite', compact('project', 'availableUsers', 'collaborators'));
    }

    /**
     * Store a new collaborator invitation
     */
    public function storeInvite(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:editor,viewer'
        ]);

        // Check if user is in same organization
        $user = User::findOrFail($validated['user_id']);
        if ($user->organization_id !== $project->organization_id) {
            abort(403, 'User must be in the same organization');
        }

        // Add collaborator
        $project->collaborators()->syncWithoutDetaching([
            $validated['user_id'] => ['role' => $validated['role']]
        ]);

        // Log activity
        $project->logActivity('collaborator_added', "Added {$user->name} as {$validated['role']}");

        return redirect()->route('projects.invite', $project)
            ->with('success', 'Collaborator added successfully!');
    }

    /**
     * Remove a collaborator
     */
    public function removeCollaborator(Project $project, User $user)
    {
        $this->authorize('update', $project);

        $project->collaborators()->detach($user->id);

        // Log activity
        $project->logActivity('collaborator_removed', "Removed {$user->name} from project");

        return redirect()->route('projects.invite', $project)
            ->with('success', 'Collaborator removed successfully!');
    }

    /**
     * Update collaborator role
     */
    public function updateRole(Request $request, Project $project, User $user)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'role' => 'required|in:owner,editor,viewer'
        ]);

        $project->collaborators()->updateExistingPivot($user->id, [
            'role' => $validated['role']
        ]);

        // Log activity
        $project->logActivity('role_updated', "Changed {$user->name}'s role to {$validated['role']}");

        return redirect()->route('projects.invite', $project)
            ->with('success', 'Role updated successfully!');
    }

    /**
     * Store a comment on the project
     */
    public function storeComment(Request $request, Project $project)
    {
        if (!$project->userCanAccess(Auth::user())) {
            abort(403);
        }

        $validated = $request->validate([
            'content' => 'required|string',
            'parent_id' => 'nullable|exists:project_comments,id'
        ]);

        $comment = $project->comments()->create([
            'user_id' => Auth::id(),
            'content' => $validated['content'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        // Log activity
        $project->logActivity('comment_added', 'Added a comment');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'comment' => $comment->load('user')
            ]);
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Comment added successfully!');
    }

    /**
     * Delete a comment
     */
    public function destroyComment(Project $project, $commentId)
    {
        $comment = $project->comments()->findOrFail($commentId);

        // Only comment author or project owner can delete
        if ($comment->user_id !== Auth::id() && $project->user_id !== Auth::id()) {
            abort(403);
        }

        $comment->delete();

        return redirect()->route('projects.show', $project)
            ->with('success', 'Comment deleted successfully!');
    }
}
