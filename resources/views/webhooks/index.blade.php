@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Webhooks</span>
                    <div>
                        @can('create', App\Models\Webhook::class)
                            <a href="{{ route('webhooks.create') }}" class="btn btn-primary btn-sm">New Webhook</a>
                        @endcan
                        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-secondary">Back</a>
                    </div>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>URL</th>
                                    <th>Events</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($webhooks as $webhook)
                                    <tr>
                                        <td>{{ $webhook->name }}</td>
                                        <td class="text-truncate" style="max-width: 280px;">{{ $webhook->url }}</td>
                                        <td>
                                            @foreach(($webhook->events ?? []) as $event)
                                                <span class="badge bg-secondary">{{ $event }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            @if($webhook->is_active)
                                                <span class="badge bg-success">Active</span>
                                            @else
                                                <span class="badge bg-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            @can('update', $webhook)
                                                <a href="{{ route('webhooks.edit', $webhook) }}" class="btn btn-sm btn-primary">Edit</a>
                                            @endcan
                                            @can('delete', $webhook)
                                                <form action="{{ route('webhooks.destroy', $webhook) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this webhook?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No webhooks configured.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-center">
                        {{ $webhooks->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
