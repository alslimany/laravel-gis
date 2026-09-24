@extends('layouts.app')

@section('content')
<div class="desk">
    <header class="desk-intro">
        <h1>Projects</h1>
        @can('create', App\Models\Project::class)
            <a href="{{ route('projects.create') }}" class="desk-primary">New project</a>
        @endcan
    </header>

    @if(session('success'))
        <p class="desk-flash">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="desk-flash desk-flash-error">{{ session('error') }}</p>
    @endif

    <section class="desk-panel">
        @if($projects->isEmpty())
            <p class="desk-empty">
                No projects yet.
                @can('create', App\Models\Project::class)
                    <a href="{{ route('projects.create') }}">Create a project</a>
                    to group layers.
                @endcan
            </p>
        @else
            <div class="desk-table-wrap">
                <table class="desk-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="desk-hide-sm">Description</th>
                            <th class="num desk-hide-sm">Layers</th>
                            <th class="num desk-hide-sm">Collaborators</th>
                            <th class="desk-hide-sm">Owner</th>
                            <th class="desk-status">Status</th>
                            <th class="desk-hide-sm">Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($projects as $project)
                            <tr>
                                <td>
                                    <a href="{{ route('projects.show', $project) }}" class="desk-name">{{ $project->name }}</a>
                                </td>
                                <td class="desk-sub desk-sub-inline desk-hide-sm">{{ $project->description ?: '—' }}</td>
                                <td class="num mono desk-hide-sm">{{ $project->layers->count() }}</td>
                                <td class="num mono desk-hide-sm">{{ $project->collaborators_count }}</td>
                                <td class="desk-hide-sm">{{ $project->user?->name ?? '—' }}</td>
                                <td class="desk-status">
                                    <span class="{{ $project->is_public ? 'pill pill-live' : 'pill' }}">
                                        {{ $project->is_public ? 'Public' : 'Private' }}
                                    </span>
                                </td>
                                <td class="desk-sub desk-sub-inline desk-hide-sm">{{ $project->created_at->format('M d, Y') }}</td>
                                <td>
                                    <div class="desk-row-actions">
                                        <a class="desk-hide-sm" href="{{ route('projects.show', $project) }}">View</a>
                                        @can('update', $project)
                                            <a href="{{ route('projects.edit', $project) }}">Edit</a>
                                            <a class="desk-hide-sm" href="{{ route('projects.share', $project) }}">Share</a>
                                            <a class="desk-hide-sm" href="{{ route('projects.invite', $project) }}">Invite</a>
                                        @endcan
                                        @can('delete', $project)
                                            <form action="{{ route('projects.destroy', $project) }}" method="POST" onsubmit="return confirm('Delete this project?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit">Delete</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($projects->hasPages())
                <div class="desk-pages">{{ $projects->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
