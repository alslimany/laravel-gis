@extends('layouts.app')

@section('content')
<div class="desk">
    <header class="desk-intro">
        <h1>Maps</h1>
    </header>

    @if(session('success'))
        <p class="desk-flash">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="desk-flash desk-flash-error">{{ session('error') }}</p>
    @endif

    <section class="desk-panel">
        @if($maps->isEmpty())
            <p class="desk-empty">No maps yet. Open the map builder to compose one.</p>
        @else
            <div class="desk-table-wrap">
                <table class="desk-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="desk-hide-sm">Description</th>
                            <th class="desk-hide-sm">Created by</th>
                            <th class="desk-status">Status</th>
                            <th class="desk-hide-sm">Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($maps as $map)
                            <tr>
                                <td>
                                    <a href="{{ route('maps.show', $map) }}" class="desk-name">{{ $map->name }}</a>
                                </td>
                                <td class="desk-sub desk-sub-inline desk-hide-sm">{{ $map->description ?: '—' }}</td>
                                <td class="desk-hide-sm">{{ $map->user?->name ?? '—' }}</td>
                                <td class="desk-status">
                                    <span class="{{ $map->is_public ? 'pill pill-live' : 'pill' }}">
                                        {{ $map->is_public ? 'Public' : 'Private' }}
                                    </span>
                                </td>
                                <td class="desk-sub desk-sub-inline desk-hide-sm">{{ $map->created_at->diffForHumans() }}</td>
                                <td>
                                    <div class="desk-row-actions">
                                        <a class="desk-hide-sm" href="{{ route('maps.show', $map) }}">View</a>
                                        <a href="{{ route('maps.builder', $map) }}">Edit</a>
                                        <a href="{{ route('maps.share', $map) }}">Share</a>
                                        <form action="{{ route('maps.destroy', $map) }}" method="POST" onsubmit="return confirm('Delete this map?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($maps->hasPages())
                <div class="desk-pages">{{ $maps->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
