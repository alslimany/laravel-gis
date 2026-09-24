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
    
    function basemapSource(id) {
        if (id === 'imagery') {
            return new ol.source.XYZ({
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                maxZoom: 19,
                attributions: 'Tiles © Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community'
            });
        }
        if (id === 'satellite' || id === 'mapbox-satellite') {
            return new ol.source.XYZ({
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                maxZoom: 19
            });
        }
        if (id === 'dark') {
            return new ol.source.XYZ({ url: 'https://{a-c}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', maxZoom: 19 });
        }
        if (id === 'light') {
            return new ol.source.XYZ({ url: 'https://{a-c}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', maxZoom: 19 });
        }
        if (id === 'terrain') {
            return new ol.source.XYZ({ url: 'https://{a-c}.tile.opentopomap.org/{z}/{x}/{y}.png', maxZoom: 17 });
        }
        return new ol.source.OSM();
    }

    const baseLayer = new ol.layer.Tile({
        source: basemapSource(mapData.basemap)
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

    function layerStyle(layerConfig) {
        const style = layerConfig.style_config || layerConfig.style || {};
        const fill = style.fill_color || style.fillColor || '#3388ff';
        const stroke = style.stroke_color || style.strokeColor || '#000000';
        const fillOpacity = Number(style.fill_opacity ?? style.fillOpacity ?? 0.5);
        const strokeWidth = Number(style.stroke_width ?? style.strokeWidth ?? 1);
        return new ol.style.Style({
            fill: new ol.style.Fill({ color: fill }),
            stroke: new ol.style.Stroke({ color: stroke, width: strokeWidth }),
            image: new ol.style.Circle({
                radius: 6,
                fill: new ol.style.Fill({ color: fill }),
                stroke: new ol.style.Stroke({ color: stroke, width: strokeWidth })
            })
        });
    }

    // Add layers if any
    if (mapData.layers && Array.isArray(mapData.layers)) {
        mapData.layers.forEach(layerConfig => {
            if (layerConfig.visible === false) {
                return;
            }

            const opacity = layerConfig.opacity || 1;

            if ((layerConfig.type === 'mvt' || layerConfig.mvtUrl) && (layerConfig.mvtUrl || layerConfig.id)) {
                const url = layerConfig.mvtUrl || `/api/layers/${layerConfig.id}/tiles/{z}/{x}/{y}.mvt`;
                map.addLayer(new ol.layer.VectorTile({
                    source: new ol.source.VectorTile({
                        format: new ol.format.MVT(),
                        url: url
                    }),
                    style: layerStyle(layerConfig),
                    opacity: opacity
                }));
                return;
            }

            if (layerConfig.type === 'wms') {
                map.addLayer(new ol.layer.Tile({
                    source: new ol.source.TileWMS({
                        url: layerConfig.url,
                        params: Object.assign({
                            'LAYERS': layerConfig.layers,
                            'TILED': true
                        }, layerConfig.wmsParams || {})
                    }),
                    opacity: opacity
                }));
                return;
            }

            if (layerConfig.type === 'vector' || layerConfig.type === 'geojson' || layerConfig.type === 'wfs') {
                map.addLayer(new ol.layer.Vector({
                    source: new ol.source.Vector({
                        url: layerConfig.url,
                        format: new ol.format.GeoJSON()
                    }),
                    style: layerStyle(layerConfig),
                    opacity: opacity
                }));
            }
        });
    }
});
</script>
@endpush
