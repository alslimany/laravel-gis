@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Layers</span>
                    <a href="{{ route('layers.create') }}" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> New Layer
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

                    @if($layers->isEmpty())
                        <div class="text-center py-5">
                            <p class="text-muted">No layers yet.</p>
                            <a href="{{ route('layers.create') }}" class="btn btn-primary">
                                Create Your First Layer
                            </a>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Project</th>
                                        <th>Type</th>
                                        <th>Features</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($layers as $layer)
                                        <tr>
                                            <td>
                                                <a href="{{ route('layers.show', $layer) }}">
                                                    {{ $layer->name }}
                                                </a>
                                            </td>
                                            <td>
                                                @if($layer->project)
                                                    {{ $layer->project->name }}
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($layer->geometry_type)
                                                    <span class="badge bg-secondary">{{ $layer->geometry_type }}</span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>{{ number_format($layer->feature_count) }}</td>
                                            <td>
                                                @if($layer->published)
                                                    <span class="badge bg-success">Published</span>
                                                @else
                                                    <span class="badge bg-secondary">Not Published</span>
                                                @endif
                                            </td>
                                            <td>{{ $layer->user->name }}</td>
                                            <td>{{ $layer->created_at->diffForHumans() }}</td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="{{ route('layers.show', $layer) }}" class="btn btn-sm btn-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="{{ route('layers.edit', $layer) }}" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form action="{{ route('layers.destroy', $layer) }}" method="POST" style="display: inline;">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this layer?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-center">
                            {{ $layers->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
