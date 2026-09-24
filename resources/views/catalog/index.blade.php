@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Catalog</h1>
    </div>

    <form method="GET" action="{{ route('catalog.index') }}" class="mb-4">
        <div class="input-group">
            <input type="search" name="q" value="{{ $q }}" class="form-control"
                   placeholder="Search layers, maps, forms, dashboards…">
            <button class="btn btn-primary" type="submit">Search</button>
            @if($q !== '')
                <a href="{{ route('catalog.index') }}" class="btn btn-outline-secondary">Clear</a>
            @endif
        </div>
    </form>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Layers ({{ $layers->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse($layers as $layer)
                        <li class="list-group-item d-flex justify-content-between">
                            <div>
                                <strong>{{ $layer->name }}</strong>
                                <div class="small text-muted">{{ $layer->geometry_type }} · {{ $layer->feature_count }} features</div>
                            </div>
                            @if(Route::has('layers.show'))
                                <a href="{{ route('layers.show', $layer) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No matching layers.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Maps ({{ $maps->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse($maps as $map)
                        <li class="list-group-item d-flex justify-content-between">
                            <div>
                                <strong>{{ $map->name }}</strong>
                                <div class="small text-muted">{{ Str::limit($map->description, 60) }}</div>
                            </div>
                            @if(Route::has('maps.show'))
                                <a href="{{ route('maps.show', $map) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No matching maps.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Forms ({{ $forms->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse($forms as $form)
                        <li class="list-group-item">
                            <strong>{{ $form->name }}</strong>
                            <div class="small text-muted">{{ Str::limit($form->description, 80) }}</div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No matching forms.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Dashboards ({{ $dashboards->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse($dashboards as $dashboard)
                        <li class="list-group-item d-flex justify-content-between">
                            <div>
                                <strong>{{ $dashboard->name }}</strong>
                                <div class="small text-muted">{{ Str::limit($dashboard->description, 60) }}</div>
                            </div>
                            @if(Route::has('dashboards.show'))
                                <a href="{{ route('dashboards.show', $dashboard) }}" class="btn btn-sm btn-outline-primary">Open</a>
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No matching dashboards.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
