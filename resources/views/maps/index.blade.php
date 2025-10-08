@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Maps</span>
                    <a href="{{ route('maps.builder') }}" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Create New Map
                    </a>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    @if($maps->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Created By</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($maps as $map)
                                        <tr>
                                            <td>
                                                <strong>{{ $map->name }}</strong>
                                            </td>
                                            <td>{{ Str::limit($map->description, 50) }}</td>
                                            <td>{{ $map->user->name }}</td>
                                            <td>
                                                @if($map->is_public)
                                                    <span class="badge bg-success">Public</span>
                                                @else
                                                    <span class="badge bg-secondary">Private</span>
                                                @endif
                                            </td>
                                            <td>{{ $map->created_at->diffForHumans() }}</td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="{{ route('maps.show', $map) }}" class="btn btn-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="{{ route('maps.builder', $map) }}" class="btn btn-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="{{ route('maps.share', $map) }}" class="btn btn-success" title="Share">
                                                        <i class="fas fa-share-alt"></i>
                                                    </a>
                                                    <form action="{{ route('maps.destroy', $map) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this map?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger" title="Delete">
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
                            {{ $maps->links() }}
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="fas fa-map fa-4x text-muted mb-3"></i>
                            <h5>No Maps Yet</h5>
                            <p class="text-muted">Create your first map to get started</p>
                            <a href="{{ route('maps.builder') }}" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Map
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
