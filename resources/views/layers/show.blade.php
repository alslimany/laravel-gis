@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <!-- Layer Details Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Layer: {{ $layer->name }}</span>
                    <div>
                        @if(!$layer->published)
                            <form action="{{ route('layers.publish', $layer) }}" method="POST" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="fas fa-cloud-upload-alt"></i> Publish to GeoServer
                                </button>
                            </form>
                        @else
                            <form action="{{ route('layers.unpublish', $layer) }}" method="POST" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fas fa-cloud-download-alt"></i> Unpublish
                                </button>
                            </form>
                        @endif
                        <a href="{{ route('layers.edit', $layer) }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="{{ route('layers.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show">
                            {{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-6">
                            <h5>General Information</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name:</th>
                                    <td>{{ $layer->name }}</td>
                                </tr>
                                <tr>
                                    <th>Description:</th>
                                    <td>{{ $layer->description ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Project:</th>
                                    <td>{{ $layer->project->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Table Name:</th>
                                    <td><code>{{ $layer->table_name }}</code></td>
                                </tr>
                                <tr>
                                    <th>Geometry Type:</th>
                                    <td>
                                        @if($layer->geometry_type)
                                            <span class="badge bg-secondary">{{ $layer->geometry_type }}</span>
                                        @else
                                            <span class="text-muted">Not detected</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Feature Count:</th>
                                    <td>{{ number_format($layer->feature_count) }}</td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5>Publishing Status</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Status:</th>
                                    <td>
                                        @if($layer->published)
                                            <span class="badge bg-success">Published</span>
                                        @else
                                            <span class="badge bg-secondary">Not Published</span>
                                        @endif
                                    </td>
                                </tr>
                                @if($layer->published)
                                    <tr>
                                        <th>GeoServer Layer:</th>
                                        <td><code>{{ $layer->geoserver_layer_name }}</code></td>
                                    </tr>
                                    <tr>
                                        <th>Workspace:</th>
                                        <td><code>{{ $layer->geoserver_workspace }}</code></td>
                                    </tr>
                                    <tr>
                                        <th>Published At:</th>
                                        <td>{{ $layer->published_at->format('Y-m-d H:i:s') }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <th>Created By:</th>
                                    <td>{{ $layer->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>Created At:</th>
                                    <td>{{ $layer->created_at->format('Y-m-d H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mt-3">
                <div class="card-header">Quick Actions</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <a href="{{ route('layers.attributes', $layer) }}" class="btn btn-outline-primary w-100">
                                <i class="fas fa-table"></i> View Attribute Table
                            </a>
                        </div>
                        <div class="col-md-6 mb-2">
                            <a href="{{ route('layers.geojson', $layer) }}" class="btn btn-outline-info w-100" target="_blank">
                                <i class="fas fa-download"></i> Download as GeoJSON
                            </a>
                        </div>
                        <div class="col-md-6 mb-2">
                            <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#previewModal">
                                <i class="fas fa-map"></i> Preview on Map
                            </button>
                        </div>
                        <div class="col-md-6 mb-2">
                            <a href="{{ route('layers.edit', $layer) }}" class="btn btn-outline-warning w-100">
                                <i class="fas fa-palette"></i> Edit Style
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Style Configuration -->
            @if($layer->style_config)
            <div class="card mt-3">
                <div class="card-header">Current Style Configuration</div>
                <div class="card-body">
                    <div class="row">
                        @foreach($layer->style_config as $key => $value)
                            <div class="col-md-3 mb-2">
                                <strong>{{ ucfirst($key) }}:</strong>
                                @if(str_contains($key, 'olor'))
                                    <div class="d-inline-block" style="width: 30px; height: 20px; background-color: {{ $value }}; border: 1px solid #ccc;"></div>
                                    <span>{{ $value }}</span>
                                @else
                                    <span>{{ $value }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalLabel">Layer Preview: {{ $layer->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="map" style="height: 500px; width: 100%;"></div>
                <p class="text-muted mt-2">
                    <small>Map preview functionality requires additional JavaScript libraries (e.g., Leaflet or OpenLayers).</small>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection
