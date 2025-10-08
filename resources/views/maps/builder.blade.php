@extends('layouts.app')

@section('content')
<div id="map-builder-app"></div>
@endsection

@push('styles')
<style>
    body {
        overflow: hidden;
    }
    #app {
        height: 100vh;
        display: flex;
        flex-direction: column;
    }
    #app main {
        flex: 1;
        padding: 0 !important;
        overflow: hidden;
    }
</style>
@endpush

@push('scripts')
@vite(['resources/js/map-builder.js'])
<script>
    // Pass initial map data if editing
    @if(isset($map))
    window.initialMapData = {
        id: {{ $map->id }},
        name: '{{ $map->name }}',
        description: '{{ $map->description ?? '' }}',
        viewport: @json($map->viewport),
        basemap: '{{ $map->basemap }}',
        layers: @json($map->layers)
    };
    @endif
</script>
@endpush
