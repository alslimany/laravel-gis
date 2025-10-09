@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Projects</span>
                    @can('create', App\Models\Project::class)
                        <a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> New Project
                        </a>
                    @endcan
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if($projects->isEmpty())
                        <div class="text-center py-5">
                            <p class="text-muted">No projects yet.</p>
                            @can('create', App\Models\Project::class)
                                <a href="{{ route('projects.create') }}" class="btn btn-primary">
                                    Create Your First Project
                                </a>
                            @endcan
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Layers</th>
                                        <th>Collaborators</th>
                                        <th>Owner</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($projects as $project)
                                        <tr>
                                            <td>
                                                <a href="{{ route('projects.show', $project) }}">
                                                    {{ $project->name }}
                                                </a>
                                            </td>
                                            <td>{{ Str::limit($project->description, 50) }}</td>
                                            <td>{{ $project->layers->count() }}</td>
                                            <td>{{ $project->collaborators_count }}</td>
                                            <td>{{ $project->user->name ?? 'N/A' }}</td>
                                            <td>
                                                @if($project->is_public)
                                                    <span class="badge bg-success">Public</span>
                                                @else
                                                    <span class="badge bg-secondary">Private</span>
                                                @endif
                                            </td>
                                            <td>{{ $project->created_at->format('M d, Y') }}</td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="{{ route('projects.show', $project) }}" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    @can('update', $project)
                                                        <a href="{{ route('projects.edit', $project) }}" 
                                                           class="btn btn-sm btn-outline-secondary" 
                                                           title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="{{ route('projects.share', $project) }}" 
                                                           class="btn btn-sm btn-outline-info" 
                                                           title="Share">
                                                            <i class="fas fa-share-alt"></i>
                                                        </a>
                                                        <a href="{{ route('projects.invite', $project) }}" 
                                                           class="btn btn-sm btn-outline-success" 
                                                           title="Collaborators">
                                                            <i class="fas fa-users"></i>
                                                        </a>
                                                    @endcan
                                                    @can('delete', $project)
                                                        <form action="{{ route('projects.destroy', $project) }}" 
                                                              method="POST" 
                                                              class="d-inline"
                                                              onsubmit="return confirm('Are you sure you want to delete this project?');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" 
                                                                    class="btn btn-sm btn-outline-danger" 
                                                                    title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    @endcan
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-center">
                            {{ $projects->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
