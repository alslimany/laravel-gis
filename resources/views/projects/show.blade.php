@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <!-- Project Header -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ $project->name }}</h5>
                    <div>
                        @if($project->is_public)
                            <span class="badge bg-success">Public</span>
                        @else
                            <span class="badge bg-secondary">Private</span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    <p class="card-text">{{ $project->description ?: 'No description provided.' }}</p>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p><strong>Owner:</strong> {{ $project->user->name ?? 'N/A' }}</p>
                            <p><strong>Organization:</strong> {{ $project->organization->name ?? 'N/A' }}</p>
                            <p><strong>Created:</strong> {{ $project->created_at->format('F d, Y') }}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Layers:</strong> {{ $project->layers->count() }}</p>
                            <p><strong>Collaborators:</strong> {{ $project->collaborators->count() }}</p>
                            <p><strong>Last Updated:</strong> {{ $project->updated_at->format('F d, Y') }}</p>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a href="{{ route('projects.index') }}" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Projects
                        </a>
                        @can('update', $project)
                            <a href="{{ route('projects.edit', $project) }}" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="{{ route('projects.share', $project) }}" class="btn btn-info">
                                <i class="fas fa-share-alt"></i> Share
                            </a>
                            <a href="{{ route('projects.invite', $project) }}" class="btn btn-success">
                                <i class="fas fa-users"></i> Manage Collaborators
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Layers -->
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h6 class="mb-0">Layers</h6>
                        </div>
                        <div class="card-body">
                            @if($project->layers->isEmpty())
                                <p class="text-muted">No layers in this project yet.</p>
                            @else
                                <ul class="list-group list-group-flush">
                                    @foreach($project->layers as $layer)
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <a href="{{ route('layers.show', $layer) }}">{{ $layer->name }}</a>
                                            <span class="badge bg-primary">{{ $layer->geometry_type }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Collaborators -->
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h6 class="mb-0">Collaborators</h6>
                        </div>
                        <div class="card-body">
                            @if($project->collaborators->isEmpty())
                                <p class="text-muted">No collaborators yet.</p>
                            @else
                                <ul class="list-group list-group-flush">
                                    @foreach($project->collaborators as $collaborator)
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            {{ $collaborator->name }}
                                            <span class="badge bg-secondary">{{ $collaborator->pivot->role }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">Recent Activity</h6>
                </div>
                <div class="card-body">
                    @if($project->activities->isEmpty())
                        <p class="text-muted">No activity yet.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach($project->activities as $activity)
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <span>
                                            <strong>{{ $activity->user->name ?? 'System' }}</strong> 
                                            {{ $activity->description }}
                                        </span>
                                        <small class="text-muted">{{ $activity->created_at->diffForHumans() }}</small>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <!-- Comments Section -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Comments</h6>
                </div>
                <div class="card-body">
                    @if(Auth::user()->organization_id === $project->organization_id || $project->collaborators->contains(Auth::id()))
                        <form method="POST" action="{{ route('projects.comments.store', $project) }}" class="mb-3">
                            @csrf
                            <div class="mb-2">
                                <textarea class="form-control" name="content" rows="3" placeholder="Add a comment..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Add Comment</button>
                        </form>
                    @endif

                    @if($project->comments->isEmpty())
                        <p class="text-muted">No comments yet.</p>
                    @else
                        @foreach($project->comments as $comment)
                            <div class="card mb-2">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong>{{ $comment->user->name ?? 'Anonymous' }}</strong>
                                            <small class="text-muted">{{ $comment->created_at->diffForHumans() }}</small>
                                        </div>
                                        @if($comment->user_id === Auth::id() || $project->user_id === Auth::id())
                                            <form method="POST" action="{{ route('projects.comments.destroy', [$project, $comment]) }}" 
                                                  onsubmit="return confirm('Delete this comment?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                    <p class="mt-2 mb-0">{{ $comment->content }}</p>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
