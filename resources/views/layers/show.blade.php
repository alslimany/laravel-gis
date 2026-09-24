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
                                        <td>{{ optional($layer->published_at)->format('Y-m-d H:i:s') ?? '—' }}</td>
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
                        @if($layer->geometry_type !== 'Raster')
                        <div class="col-md-6 mb-2">
                            <a href="{{ route('layers.attributes', ['layer' => $layer->id]) }}" class="btn btn-outline-primary w-100">
                                <i class="fas fa-table"></i> View Attribute Table
                            </a>
                        </div>
                        <div class="col-md-6 mb-2">
                            <a href="{{ route('layers.geojson', ['layer' => $layer->id]) }}" class="btn btn-outline-info w-100" target="_blank">
                                <i class="fas fa-download"></i> Download as GeoJSON
                            </a>
                        </div>
                        @else
                        <div class="col-12 mb-2">
                            <p class="small mb-0" style="color:#3f4c5e;">
                                Imagery layer. Latest capture {{ $layer->metadata['acquired_at'] ?? 'date not set' }}.
                                {{ count($layer->metadata['granules'] ?? []) }} scene{{ count($layer->metadata['granules'] ?? []) === 1 ? '' : 's' }}.
                            </p>
                        </div>
                        @endif
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

            @if($layer->geometry_type !== 'Raster')
            <!-- Field registry -->
            <div class="card mt-3" id="layer-fields-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Field registry</span>
                    <button type="button" class="btn btn-sm btn-primary" id="add-layer-field">Add field</button>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Aliases, domains, required flags, and calculated expressions for this layer.</p>
                    <div class="table-responsive">
                        <table class="table table-sm" id="layer-fields-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Alias</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                    <th>Domain</th>
                                    <th>Calculated</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
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
                
                @if($layer->geometry_type === 'Raster')
                L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                    attribution: 'Tiles © Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
                    maxZoom: 19
                }).addTo(map);
                @else
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 19
                }).addTo(map);
                @endif

                document.getElementById('map-loading').style.display = 'block';
                document.getElementById('map').style.display = 'block';

                @if($layer->geometry_type === 'Raster')
                const imagery = L.tileLayer.wms(@json(rtrim(config('geoserver.public_url'), '/').'/wms'), {
                    layers: @json(($layer->geoserver_workspace ? $layer->geoserver_workspace.':' : '').($layer->geoserver_layer_name ?: $layer->table_name)),
                    format: 'image/png',
                    transparent: true,
                    version: '1.1.1',
                    SORTING: 'acquired D'
                }).addTo(map);
                const bbox = @json($layer->metadata['bbox'] ?? null);
                document.getElementById('map-loading').style.display = 'none';
                if (Array.isArray(bbox) && bbox.length === 4) {
                    map.fitBounds([[bbox[1], bbox[0]], [bbox[3], bbox[2]]]);
                }
                setTimeout(() => map.invalidateSize(), 100);
                return;
                @endif

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

<script>
(function () {
    const layerId = {{ $layer->id }};
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const tbody = document.querySelector('#layer-fields-table tbody');
    if (!tbody) return;

    function headers(json) {
        const h = { 'X-CSRF-TOKEN': csrf || '', 'Accept': 'application/json' };
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async function loadFields() {
        const res = await fetch(`/api/layers/${layerId}/fields`, { headers: headers() });
        const data = await res.json();
        const fields = data.fields || data.data || [];
        tbody.innerHTML = '';
        fields.forEach((field) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><code>${field.name}</code></td>
                <td><input class="form-control form-control-sm" data-k="alias" value="${field.alias || ''}"></td>
                <td><input class="form-control form-control-sm" data-k="type" value="${field.type || 'text'}"></td>
                <td><input type="checkbox" data-k="required" ${field.required ? 'checked' : ''}></td>
                <td><input class="form-control form-control-sm" data-k="domain" value="${(field.domain_values || []).join(',')}"></td>
                <td><input class="form-control form-control-sm" data-k="calc" value="${field.calculated_expression || ''}"></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary save-field">Save</button>
                    <button type="button" class="btn btn-sm btn-outline-danger del-field">Delete</button>
                </td>`;
            tr.dataset.id = field.id;
            tbody.appendChild(tr);
        });
    }

    tbody.addEventListener('click', async (event) => {
        const tr = event.target.closest('tr');
        if (!tr) return;
        const id = tr.dataset.id;
        if (event.target.classList.contains('save-field')) {
            const payload = {
                alias: tr.querySelector('[data-k="alias"]').value || null,
                type: tr.querySelector('[data-k="type"]').value || 'text',
                required: tr.querySelector('[data-k="required"]').checked,
                domain_values: tr.querySelector('[data-k="domain"]').value
                    .split(',').map((s) => s.trim()).filter(Boolean),
                calculated_expression: tr.querySelector('[data-k="calc"]').value || null,
            };
            await fetch(`/api/layers/${layerId}/fields/${id}`, {
                method: 'PUT',
                headers: headers(true),
                body: JSON.stringify(payload),
            });
            await loadFields();
        }
        if (event.target.classList.contains('del-field')) {
            if (!confirm('Delete this field definition?')) return;
            await fetch(`/api/layers/${layerId}/fields/${id}`, {
                method: 'DELETE',
                headers: headers(),
            });
            await loadFields();
        }
    });

    document.getElementById('add-layer-field')?.addEventListener('click', async () => {
        const name = prompt('Column name (must exist on the table):');
        if (!name) return;
        await fetch(`/api/layers/${layerId}/fields`, {
            method: 'POST',
            headers: headers(true),
            body: JSON.stringify({ name, type: 'string', alias: name }),
        });
        await loadFields();
    });

    loadFields();
})();
</script>
@endpush
