<template>
    <div ref="mapContainer" class="ol-map"></div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useMapStore } from '../../stores/mapStore';
import Map from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import OSM from 'ol/source/OSM';
import BingMaps from 'ol/source/BingMaps';
import VectorSource from 'ol/source/Vector';
import TileWMS from 'ol/source/TileWMS';
import GeoJSON from 'ol/format/GeoJSON';
import { fromLonLat } from 'ol/proj';
import { defaults as defaultControls, FullScreen, ScaleLine, ZoomSlider } from 'ol/control';
import Select from 'ol/interaction/Select';
import { click } from 'ol/events/condition';
import Overlay from 'ol/Overlay';
import 'ol/ol.css';

const mapContainer = ref(null);
const mapStore = useMapStore();
let map = null;
let selectInteraction = null;
let popup = null;
let popupOverlay = null;

onMounted(() => {
    initializeMap();
});

const initializeMap = () => {
    // Create popup element
    popup = document.createElement('div');
    popup.className = 'ol-popup';
    popup.style.cssText = `
        background: white;
        padding: 15px;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        min-width: 200px;
    `;

    // Create popup overlay
    popupOverlay = new Overlay({
        element: popup,
        autoPan: true,
        autoPanAnimation: {
            duration: 250
        }
    });

    // Create base layer
    const baseLayer = createBaseLayer(mapStore.basemap);

    // Initialize map
    map = new Map({
        target: mapContainer.value,
        layers: [baseLayer],
        overlays: [popupOverlay],
        view: new View({
            center: fromLonLat(mapStore.viewport.center),
            zoom: mapStore.viewport.zoom,
            rotation: mapStore.viewport.rotation
        }),
        controls: defaultControls().extend([
            new FullScreen(),
            new ScaleLine(),
            new ZoomSlider()
        ])
    });

    // Store map instance
    mapStore.setMap(map);

    // Add select interaction
    selectInteraction = new Select({
        condition: click
    });
    map.addInteraction(selectInteraction);

    // Handle feature selection
    selectInteraction.on('select', (event) => {
        if (event.selected.length > 0) {
            const feature = event.selected[0];
            const properties = feature.getProperties();
            
            let content = '<div class="feature-info"><strong>Feature Properties:</strong><br>';
            for (let key in properties) {
                if (key !== 'geometry') {
                    content += `<strong>${key}:</strong> ${properties[key]}<br>`;
                }
            }
            content += '</div>';
            
            popup.innerHTML = content;
            popupOverlay.setPosition(event.mapBrowserEvent.coordinate);
        } else {
            popupOverlay.setPosition(undefined);
        }
    });

    // Track viewport changes
    map.getView().on('change', () => {
        const view = map.getView();
        const center = view.getCenter();
        const zoom = view.getZoom();
        const rotation = view.getRotation();
        
        mapStore.updateViewport({
            center: center,
            zoom: zoom,
            rotation: rotation
        });
    });
};

const createBaseLayer = (basemapId) => {
    switch (basemapId) {
        case 'bing-aerial':
            return new TileLayer({
                source: new BingMaps({
                    key: 'YOUR_BING_MAPS_KEY', // Should be from config
                    imagerySet: 'Aerial'
                })
            });
        case 'bing-road':
            return new TileLayer({
                source: new BingMaps({
                    key: 'YOUR_BING_MAPS_KEY', // Should be from config
                    imagerySet: 'Road'
                })
            });
        case 'osm':
        default:
            return new TileLayer({
                source: new OSM()
            });
    }
};

// Watch for basemap changes
watch(() => mapStore.basemap, (newBasemap) => {
    if (map) {
        const layers = map.getLayers();
        const baseLayer = createBaseLayer(newBasemap);
        layers.setAt(0, baseLayer);
    }
});

// Watch for layer changes
watch(() => mapStore.layers, (newLayers) => {
    if (map) {
        // Remove all layers except base layer
        const layers = map.getLayers();
        while (layers.getLength() > 1) {
            layers.removeAt(1);
        }

        // Add new layers
        newLayers.forEach(layerConfig => {
            if (layerConfig.visible) {
                addLayerToMap(layerConfig);
            }
        });
    }
}, { deep: true });

const addLayerToMap = (layerConfig) => {
    let layer = null;

    if (layerConfig.type === 'wms') {
        layer = new TileLayer({
            source: new TileWMS({
                url: layerConfig.url,
                params: {
                    'LAYERS': layerConfig.layers,
                    'TILED': true
                },
                serverType: 'geoserver'
            })
        });
    } else if (layerConfig.type === 'vector') {
        layer = new VectorLayer({
            source: new VectorSource({
                url: layerConfig.url,
                format: new GeoJSON()
            })
        });
    }

    if (layer) {
        layer.set('id', layerConfig.id);
        map.addLayer(layer);
    }
};
</script>

<style scoped>
.ol-map {
    width: 100%;
    height: 100%;
}

:deep(.ol-popup) {
    position: absolute;
    background-color: white;
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    padding: 15px;
    border-radius: 10px;
    border: 1px solid #cccccc;
    bottom: 12px;
    left: -50px;
    min-width: 280px;
}

:deep(.ol-popup:after), :deep(.ol-popup:before) {
    top: 100%;
    border: solid transparent;
    content: " ";
    height: 0;
    width: 0;
    position: absolute;
    pointer-events: none;
}

:deep(.ol-popup:after) {
    border-top-color: white;
    border-width: 10px;
    left: 48px;
    margin-left: -10px;
}

:deep(.ol-popup:before) {
    border-top-color: #cccccc;
    border-width: 11px;
    left: 48px;
    margin-left: -11px;
}
</style>
