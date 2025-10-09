@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Manage Collaborators: {{ $project->name }}</span>
                    <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
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

                    <!-- Add New Collaborator -->
                    @if($availableUsers->count() > 0)
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">Add Collaborator</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="{{ route('projects.invite.store', $project) }}">
                                    @csrf
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="user_id" class="form-label">Select User</label>
                                            <select class="form-select @error('user_id') is-invalid @enderror" 
                                                    id="user_id" 
                                                    name="user_id" 
                                                    required>
                                                <option value="">Choose a user...</option>
                                                @foreach($availableUsers as $user)
                                                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                                @endforeach
                                            </select>
                                            @error('user_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="role" class="form-label">Role</label>
                                            <select class="form-select @error('role') is-invalid @enderror" 
                                                    id="role" 
                                                    name="role" 
                                                    required>
                                                <option value="viewer">Viewer</option>
                                                <option value="editor">Editor</option>
                                            </select>
                                            @error('role')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="fas fa-plus"></i> Add
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                
                                <div class="alert alert-info mb-0">
                                    <small>
                                        <strong>Roles:</strong><br>
                                        <strong>Viewer:</strong> Can view the project and its layers<br>
                                        <strong>Editor:</strong> Can view and edit the project and its layers
                                    </small>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Current Collaborators -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Current Collaborators</h6>
                        </div>
                        <div class="card-body">
                            @if($collaborators->isEmpty())
                                <p class="text-muted mb-0">No collaborators yet. Add users from your organization to collaborate on this project.</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Role</th>
                                                <th>Added</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($collaborators as $collaborator)
                                                <tr>
                                                    <td>{{ $collaborator->name }}</td>
                                                    <td>{{ $collaborator->email }}</td>
                                                    <td>
                                                        <form method="POST" 
                                                              action="{{ route('projects.collaborators.role', [$project, $collaborator]) }}" 
                                                              class="d-inline">
                                                            @csrf
                                                            @method('PUT')
                                                            <select name="role" 
                                                                    class="form-select form-select-sm" 
                                                                    onchange="this.form.submit()"
                                                                    style="width: auto; display: inline-block;">
                                                                <option value="viewer" {{ $collaborator->pivot->role === 'viewer' ? 'selected' : '' }}>Viewer</option>
                                                                <option value="editor" {{ $collaborator->pivot->role === 'editor' ? 'selected' : '' }}>Editor</option>
                                                                <option value="owner" {{ $collaborator->pivot->role === 'owner' ? 'selected' : '' }}>Owner</option>
                                                            </select>
                                                        </form>
                                                    </td>
                                                    <td>{{ $collaborator->pivot->created_at->format('M d, Y') }}</td>
                                                    <td>
                                                        <form method="POST" 
                                                              action="{{ route('projects.collaborators.remove', [$project, $collaborator]) }}" 
                                                              class="d-inline"
                                                              onsubmit="return confirm('Remove {{ $collaborator->name }} from this project?');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-times"></i> Remove
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($availableUsers->isEmpty() && $collaborators->isEmpty())
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            No other users in your organization to add as collaborators.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
