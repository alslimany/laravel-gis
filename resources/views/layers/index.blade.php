@extends('layouts.app')

@section('content')
<div class="desk">
    <header class="desk-intro">
        <h1>Layers</h1>
        <a href="{{ route('layers.create') }}" class="desk-primary">New layer</a>
    </header>

    @if(session('success'))
        <p class="desk-flash">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="desk-flash desk-flash-error">{{ session('error') }}</p>
    @endif

    <section class="desk-panel">
        @if($layers->isEmpty())
            <p class="desk-empty">
                No layers yet.
                <a href="{{ route('layers.create') }}">Create a layer</a>
                after you import a dataset.
            </p>
        @else
            <div class="desk-table-wrap">
                <table class="desk-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="desk-hide-sm">Project</th>
                            <th class="desk-hide-sm">Type</th>
                            <th class="num desk-hide-sm">Features</th>
                            <th class="desk-status">Status</th>
                            <th class="desk-hide-sm">Created by</th>
                            <th class="desk-hide-sm">Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($layers as $layer)
                            <tr>
                                <td>
                                    <a href="{{ route('layers.show', $layer) }}" class="desk-name">{{ $layer->name }}</a>
                                </td>
                                <td class="desk-hide-sm">{{ $layer->project?->name ?? '—' }}</td>
                                <td class="mono desk-hide-sm">{{ $layer->geometry_type ?: '—' }}</td>
                                <td class="num mono desk-hide-sm">{{ number_format($layer->feature_count) }}</td>
                                <td class="desk-status">
                                    <span class="{{ $layer->published ? 'pill pill-live' : 'pill' }}">
                                        {{ $layer->published ? 'Published' : 'Draft' }}
                                    </span>
                                </td>
                                <td class="desk-hide-sm">{{ $layer->user->name ?? '—' }}</td>
                                <td class="desk-sub desk-sub-inline desk-hide-sm">{{ $layer->created_at->diffForHumans() }}</td>
                                <td>
                                    <div class="desk-row-actions">
                                        <a href="{{ route('layers.show', $layer) }}">View</a>
                                        <a href="{{ route('layers.edit', $layer) }}">Edit</a>
                                        <form action="{{ route('layers.destroy', $layer) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" onclick="return confirm('Delete this layer?')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($layers->hasPages())
                <div class="desk-pages">{{ $layers->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
