@extends('layouts.app')

@section('content')
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ $dashboard->name }}</h1>
            @if($dashboard->description)
                <p class="text-muted mb-0">{{ $dashboard->description }}</p>
            @endif
        </div>
        <div class="d-flex gap-2">
            @if($dashboard->is_public && $dashboard->share_token)
                <a class="btn btn-outline-success btn-sm" href="{{ route('dashboards.public', $dashboard->share_token) }}" target="_blank">
                    Public link
                </a>
            @endif
            <a href="{{ route('dashboards.data', $dashboard) }}" class="btn btn-outline-secondary btn-sm">JSON data</a>
            <a href="{{ route('dashboards.edit', $dashboard) }}" class="btn btn-primary btn-sm">Edit</a>
            <form method="POST" action="{{ route('dashboards.destroy', $dashboard) }}" onsubmit="return confirm('Delete this dashboard?')">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
            </form>
        </div>
    </div>

    <div
        id="dashboard-board-root"
        data-dashboard-id="{{ $dashboard->id }}"
        data-data-url="{{ route('dashboards.data', $dashboard) }}"
    ></div>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/dashboards/main.jsx'])
@endpush
