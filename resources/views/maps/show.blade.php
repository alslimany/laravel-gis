@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ $map->name }}</span>
                    <div>
                        <a href="{{ route('maps.builder', $map) }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="{{ route('maps.share', $map) }}" class="btn btn-success btn-sm">
                            <i class="fas fa-share-alt"></i> Share
                        </a>
                        <a href="{{ route('maps.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if($map->description)
                        <div class="alert alert-info">
                            <strong>Description:</strong> {{ $map->description }}
                        </div>
                    @endif

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <strong>Created by:</strong> {{ $map->user->name }}
                        </div>
                        <div class="col-md-4">
                            <strong>Status:</strong>
                            @if($map->is_public)
                                <span class="badge bg-success">Public</span>
                            @else
                                <span class="badge bg-secondary">Private</span>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <strong>Created:</strong> {{ $map->created_at->format('M d, Y') }}
                        </div>
                    </div>

                    <div id="map-view" style="height: 600px; width: 100%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mapData = @json($map);
    
    // Create base layer
    const baseLayer = new ol.layer.Tile({
        source: new ol.source.OSM()
    });

    // Initialize map
    const map = new ol.Map({
        target: 'map-view',
        layers: [baseLayer],
        view: new ol.View({
            center: ol.proj.fromLonLat(mapData.viewport?.center || [0, 0]),
            zoom: mapData.viewport?.zoom || 2
        })
    });

    // Add layers if any
    if (mapData.layers && Array.isArray(mapData.layers)) {
        mapData.layers.forEach(layerConfig => {
            if (layerConfig.type === 'wms' && layerConfig.visible) {
                const wmsLayer = new ol.layer.Tile({
                    source: new ol.source.TileWMS({
                        url: layerConfig.url,
                        params: {
                            'LAYERS': layerConfig.layers,
                            'TILED': true
                        }
                    }),
                    opacity: layerConfig.opacity || 1
                });
                map.addLayer(wmsLayer);
            }
        });
    }
});
</script>
@endpush
