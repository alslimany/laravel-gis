@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Dashboard</span>
                    @if($organization)
                        <span class="badge bg-primary">{{ $organization->name }}</span>
                    @endif
                </div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            {{ session('status') }}
                        </div>
                    @endif

                    <h5>Welcome, {{ $user->name }}!</h5>
                    
                    @if($organization)
                        <div class="mt-4">
                            <h6>Your Roles:</h6>
                            @if($user->roles->count() > 0)
                                <div>
                                    @foreach($user->roles as $role)
                                        <span class="badge bg-secondary">{{ ucfirst($role->name) }}</span>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-muted">No roles assigned</p>
                            @endif
                        </div>

                        <div class="mt-4">
                            <h6>Organization Information:</h6>
                            <p><strong>Name:</strong> {{ $organization->name }}</p>
                            @if($organization->description)
                                <p><strong>Description:</strong> {{ $organization->description }}</p>
                            @endif
                            
                            @can('update', $organization)
                                <a href="{{ route('organization.settings') }}" class="btn btn-sm btn-primary">Organization Settings</a>
                            @endcan
                        </div>
                    @else
                        <div class="alert alert-warning mt-3">
                            You are not assigned to any organization. Please contact an administrator.
                        </div>
                    @endif

                    @if($user->isAdmin())
                        <div class="mt-4">
                            <a href="{{ route('users.index') }}" class="btn btn-primary">Manage Users</a>
                        </div>
                    @endif
                </div>
            </div>

            @if($organization && $projects->count() > 0)
                <div class="card">
                    <div class="card-header">
                        Recent Projects
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($projects as $project)
                                        <tr>
                                            <td>{{ $project->name }}</td>
                                            <td>{{ $project->description }}</td>
                                            <td>{{ $project->created_at->format('M d, Y') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $projects->links() }}
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
