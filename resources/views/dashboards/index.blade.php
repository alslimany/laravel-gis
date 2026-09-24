@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Insight Dashboards</h1>
        <a href="{{ route('dashboards.create') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Dashboard
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Widgets</th>
                        <th>Visibility</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dashboards as $dashboard)
                        <tr>
                            <td>
                                <a href="{{ route('dashboards.show', $dashboard) }}">{{ $dashboard->name }}</a>
                                @if($dashboard->description)
                                    <div class="small text-muted">{{ Str::limit($dashboard->description, 80) }}</div>
                                @endif
                            </td>
                            <td>{{ is_array($dashboard->widgets) ? count($dashboard->widgets) : 0 }}</td>
                            <td>
                                @if($dashboard->is_public)
                                    <span class="badge bg-success">Public</span>
                                @else
                                    <span class="badge bg-secondary">Private</span>
                                @endif
                            </td>
                            <td>{{ $dashboard->updated_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <a href="{{ route('dashboards.edit', $dashboard) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No dashboards yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($dashboards->hasPages())
            <div class="card-footer">{{ $dashboards->links() }}</div>
        @endif
    </div>
</div>
@endsection
