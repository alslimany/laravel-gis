@extends('layouts.map-builder')

@section('content')
<div id="map-builder-app" style="height:100vh;width:100%;"></div>
@endsection

@push('scripts')
<script>
    @php
        $seedLayers = [];
        $seedViewport = null;
        $seedBasemap = 'osm';
        if (!empty($seedLayer)) {
            $isRaster = $seedLayer->geometry_type === 'Raster';
            $seedBasemap = $isRaster ? 'imagery' : 'osm';
            if ($isRaster) {
                $workspace = $seedLayer->geoserver_workspace;
                $coverage = $seedLayer->geoserver_layer_name ?: $seedLayer->table_name;
                $seedLayers[] = [
                    'id' => $seedLayer->id,
                    'name' => $seedLayer->name,
                    'type' => 'wms',
                    'visible' => true,
                    'url' => rtrim(config('geoserver.public_url'), '/').'/wms',
                    'layers' => $workspace ? $workspace.':'.$coverage : $coverage,
                    'wmsParams' => data_get($seedLayer->metadata, 'wms_params', ['SORTING' => 'acquired D']),
                    'geometry_type' => 'Raster',
                    'opacity' => 1,
                ];
                $bbox = data_get($seedLayer->metadata, 'bbox');
                if (is_array($bbox) && count($bbox) === 4) {
                    $seedViewport = [
                        'center' => [($bbox[0] + $bbox[2]) / 2, ($bbox[1] + $bbox[3]) / 2],
                        'zoom' => 12,
                        'rotation' => 0,
                    ];
                }
            } else {
                $seedLayers[] = [
                    'id' => $seedLayer->id,
                    'name' => $seedLayer->name,
                    'type' => 'mvt',
                    'visible' => true,
                    'mvtUrl' => "/api/layers/{$seedLayer->id}/tiles/{z}/{x}/{y}.mvt",
                    'style_config' => $seedLayer->style_config ?: ['renderer' => 'simple'],
                    'geometry_type' => $seedLayer->geometry_type,
                ];
            }
        }
        $initialLayers = isset($map) && is_array($map->layers) ? $map->layers : $seedLayers;
        if (!empty($seedLayer) && isset($map) && is_array($map->layers)) {
            $ids = collect($map->layers)->pluck('id')->all();
            if (!in_array($seedLayer->id, $ids, true)) {
                $initialLayers = array_merge($seedLayers, $map->layers);
            }
        }
    @endphp
    window.initialMapData = {
        @if(isset($map))
        id: {{ $map->id }},
        name: @json($map->name),
        description: @json($map->description ?? ''),
        viewport: @json($map->viewport),
        basemap: @json($map->basemap),
        @else
        id: null,
        name: @json($seedLayer->name ?? 'Untitled map'),
        description: '',
        viewport: @json($seedViewport),
        basemap: @json($seedBasemap),
        @endif
        layers: @json($initialLayers)
    };
</script>
@endpush
