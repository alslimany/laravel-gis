@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Attribute Table: {{ $layer->name }}</span>
                    <div>
                        <a href="{{ route('layers.show', $layer) }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Layer
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    <!-- Search and Filter -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <form method="GET" action="{{ route('layers.attributes', $layer) }}">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Search in all columns..." 
                                           value="{{ request('search') }}">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                    @if(request('search'))
                                        <a href="{{ route('layers.attributes', $layer) }}" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
                                    @endif
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="btn-group">
                                <a href="{{ route('layers.attributes', array_merge(request()->all(), ['per_page' => 10])) }}" 
                                   class="btn btn-sm btn-outline-secondary {{ request('per_page', 25) == 10 ? 'active' : '' }}">10</a>
                                <a href="{{ route('layers.attributes', array_merge(request()->all(), ['per_page' => 25])) }}" 
                                   class="btn btn-sm btn-outline-secondary {{ request('per_page', 25) == 25 ? 'active' : '' }}">25</a>
                                <a href="{{ route('layers.attributes', array_merge(request()->all(), ['per_page' => 50])) }}" 
                                   class="btn btn-sm btn-outline-secondary {{ request('per_page', 25) == 50 ? 'active' : '' }}">50</a>
                                <a href="{{ route('layers.attributes', array_merge(request()->all(), ['per_page' => 100])) }}" 
                                   class="btn btn-sm btn-outline-secondary {{ request('per_page', 25) == 100 ? 'active' : '' }}">100</a>
                            </div>
                            <span class="text-muted ms-2">per page</span>
                        </div>
                    </div>

                    <!-- Data Table -->
                    @if($features->isEmpty())
                        <div class="alert alert-info">
                            No features found in this layer.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        @foreach($columns as $column)
                                            <th>{{ ucfirst(str_replace('_', ' ', $column)) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($features as $feature)
                                        <tr>
                                            @foreach($columns as $column)
                                                <td>
                                                    @if(in_array($column, ['geom', 'geometry']))
                                                        <code class="text-muted" style="font-size: 0.75rem;">
                                                            {{ Str::limit($feature->$column ?? '-', 50) }}
                                                        </code>
                                                    @else
                                                        {{ $feature->$column ?? '-' }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div>
                                Showing {{ $features->firstItem() }} to {{ $features->lastItem() }} of {{ $features->total() }} features
                            </div>
                            <div>
                                {{ $features->appends(request()->query())->links() }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Feature Statistics -->
            <div class="card mt-3">
                <div class="card-header">Layer Statistics</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center">
                                <h4>{{ number_format($layer->feature_count) }}</h4>
                                <p class="text-muted">Total Features</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h4>{{ count($columns) }}</h4>
                                <p class="text-muted">Attributes</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h4>{{ $layer->geometry_type ?? 'N/A' }}</h4>
                                <p class="text-muted">Geometry Type</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h4>{{ $layer->published ? 'Yes' : 'No' }}</h4>
                                <p class="text-muted">Published</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    .table-responsive {
        max-height: 600px;
        overflow-y: auto;
    }
    
    .table thead th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        z-index: 10;
    }
</style>
@endpush
