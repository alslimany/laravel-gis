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

            @if($organization && isset($stats))
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary">{{ $stats['total_layers'] }}</h3>
                                <p class="mb-0">Total Layers</p>
                                <small class="text-muted">{{ $stats['published_layers'] }} published</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success">{{ $stats['total_maps'] }}</h3>
                                <p class="mb-0">Maps</p>
                                <a href="{{ route('maps.index') }}" class="btn btn-sm btn-link">View all</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info">{{ $stats['total_projects'] }}</h3>
                                <p class="mb-0">Projects</p>
                                <a href="{{ route('projects.index') }}" class="btn btn-sm btn-link">View all</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning">{{ $stats['storage_usage'] }}</h3>
                                <p class="mb-0">Storage Used</p>
                                <small class="text-muted">Estimated</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Recent Layers</span>
                                <a href="{{ route('layers.index') }}" class="btn btn-sm btn-primary">View All</a>
                            </div>
                            <div class="card-body">
                                @if($stats['recent_layers']->count() > 0)
                                    <div class="list-group list-group-flush">
                                        @foreach($stats['recent_layers'] as $layer)
                                            <a href="{{ route('layers.show', $layer) }}" class="list-group-item list-group-item-action">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1">{{ $layer->name }}</h6>
                                                    <small>{{ $layer->created_at->diffForHumans() }}</small>
                                                </div>
                                                <p class="mb-1 text-muted small">
                                                    {{ $layer->geometry_type }} | {{ number_format($layer->feature_count) }} features
                                                    @if($layer->published)
                                                        <span class="badge bg-success">Published</span>
                                                    @endif
                                                </p>
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-muted">No layers yet. <a href="{{ route('imports.create') }}">Import data</a> to get started.</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Recent Maps</span>
                                <a href="{{ route('maps.builder') }}" class="btn btn-sm btn-success">Create Map</a>
                            </div>
                            <div class="card-body">
                                @if($stats['recent_maps']->count() > 0)
                                    <div class="list-group list-group-flush">
                                        @foreach($stats['recent_maps'] as $map)
                                            <a href="{{ route('maps.show', $map) }}" class="list-group-item list-group-item-action">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1">{{ $map->name }}</h6>
                                                    <small>{{ $map->created_at->diffForHumans() }}</small>
                                                </div>
                                                <p class="mb-1 text-muted small">
                                                    {{ count($map->layers ?? []) }} layers
                                                    @if($map->is_public)
                                                        <span class="badge bg-info">Public</span>
                                                    @endif
                                                </p>
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-muted">No maps yet. <a href="{{ route('maps.builder') }}">Create your first map</a>.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif

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
