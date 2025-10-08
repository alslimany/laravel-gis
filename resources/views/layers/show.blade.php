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
                <div id="map-loading" class="text-center my-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading map...</span>
                    </div>
                    <p class="text-muted mt-2">Loading map data...</p>
                </div>
                <div id="map-error" class="alert alert-warning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="map-error-message">Unable to load map data.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
     integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
     crossorigin=""/>
@endpush

@push('scripts')
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
     integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
     crossorigin=""></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let map = null;
    let geojsonLayer = null;
    
    // Initialize map when modal is shown
    const previewModal = document.getElementById('previewModal');
    if (previewModal) {
        previewModal.addEventListener('shown.bs.modal', function () {
            // Initialize map if not already done
            if (!map) {
                map = L.map('map').setView([0, 0], 2);
                
                // Add OpenStreetMap tile layer
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 19
                }).addTo(map);
                
                // Show loading indicator
                document.getElementById('map-loading').style.display = 'block';
                document.getElementById('map').style.display = 'block';
                
                // Load GeoJSON data
                fetch('{{ route('layers.geojson', $layer) }}')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Failed to load layer data');
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Hide loading indicator
                        document.getElementById('map-loading').style.display = 'none';
                        
                        if (!data.features || data.features.length === 0) {
                            document.getElementById('map-error-message').textContent = 'No features found in this layer.';
                            document.getElementById('map-error').style.display = 'block';
                            return;
                        }
                        
                        // Add GeoJSON layer to map
                        geojsonLayer = L.geoJSON(data, {
                            style: function(feature) {
                                return {
                                    color: '{{ $layer->style_config['stroke_color'] ?? '#3388ff' }}',
                                    weight: {{ $layer->style_config['stroke_width'] ?? 3 }},
                                    opacity: {{ $layer->style_config['stroke_opacity'] ?? 1 }},
                                    fillColor: '{{ $layer->style_config['fill_color'] ?? '#3388ff' }}',
                                    fillOpacity: {{ $layer->style_config['fill_opacity'] ?? 0.2 }}
                                };
                            },
                            pointToLayer: function(feature, latlng) {
                                return L.circleMarker(latlng, {
                                    radius: {{ $layer->style_config['point_radius'] ?? 6 }},
                                    fillColor: '{{ $layer->style_config['fill_color'] ?? '#3388ff' }}',
                                    color: '{{ $layer->style_config['stroke_color'] ?? '#3388ff' }}',
                                    weight: {{ $layer->style_config['stroke_width'] ?? 2 }},
                                    opacity: {{ $layer->style_config['stroke_opacity'] ?? 1 }},
                                    fillOpacity: {{ $layer->style_config['fill_opacity'] ?? 0.8 }}
                                });
                            },
                            onEachFeature: function(feature, layer) {
                                // Add popup with feature properties
                                if (feature.properties) {
                                    let popupContent = '<div class="feature-popup"><strong>Feature Properties:</strong><br>';
                                    for (let key in feature.properties) {
                                        if (feature.properties.hasOwnProperty(key)) {
                                            popupContent += `<strong>${key}:</strong> ${feature.properties[key]}<br>`;
                                        }
                                    }
                                    popupContent += '</div>';
                                    layer.bindPopup(popupContent);
                                }
                            }
                        }).addTo(map);
                        
                        // Fit map bounds to layer
                        if (geojsonLayer.getBounds().isValid()) {
                            map.fitBounds(geojsonLayer.getBounds(), { padding: [50, 50] });
                        }
                    })
                    .catch(error => {
                        console.error('Error loading layer:', error);
                        document.getElementById('map-loading').style.display = 'none';
                        document.getElementById('map-error-message').textContent = error.message;
                        document.getElementById('map-error').style.display = 'block';
                    });
            } else {
                // Map already exists, just invalidate size for proper display
                setTimeout(() => map.invalidateSize(), 100);
            }
        });
    }
});
</script>
@endpush
