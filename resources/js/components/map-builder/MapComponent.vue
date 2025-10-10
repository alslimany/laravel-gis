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
import XYZ from 'ol/source/XYZ';
import VectorSource from 'ol/source/Vector';
import TileWMS from 'ol/source/TileWMS';
import GeoJSON from 'ol/format/GeoJSON';
import { fromLonLat } from 'ol/proj';
import { defaults as defaultControls, FullScreen, ScaleLine, ZoomSlider } from 'ol/control';
import Select from 'ol/interaction/Select';
import { click } from 'ol/events/condition';
import Overlay from 'ol/Overlay';
import { Style, Stroke, Fill, Circle as CircleStyle } from 'ol/style';
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
    // MapBox access token - should be configured in environment
    const mapboxToken = import.meta.env.VITE_MAPBOX_TOKEN || 'pk.eyJ1IjoibWFwYm94IiwiYSI6ImNpejY4NXVycTA2emYycXBndHRqcmZ3N3gifQ.rJcFIG214AriISLbB6B5aw';
    
    switch (basemapId) {
        case 'satellite':
        case 'mapbox-satellite':
            return new TileLayer({
                source: new XYZ({
                    url: `https://api.mapbox.com/styles/v1/mapbox/satellite-v9/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    tileSize: 512,
                    maxZoom: 19,
                    attributions: '© <a href="https://www.mapbox.com/about/maps/">Mapbox</a> © <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                })
            });
        case 'mapbox-streets':
            return new TileLayer({
                source: new XYZ({
                    url: `https://api.mapbox.com/styles/v1/mapbox/streets-v11/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    tileSize: 512,
                    maxZoom: 19,
                    attributions: '© <a href="https://www.mapbox.com/about/maps/">Mapbox</a> © <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                })
            });
        case 'terrain':
            return new TileLayer({
                source: new OSM({
                    url: 'https://{a-c}.tile.opentopomap.org/{z}/{x}/{y}.png',
                    attributions: 'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="http://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a>'
                })
            });
        case 'dark':
            return new TileLayer({
                source: new OSM({
                    url: 'https://{a-c}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                    attributions: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>'
                })
            });
        case 'light':
            return new TileLayer({
                source: new OSM({
                    url: 'https://{a-c}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                    attributions: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>'
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
            }),
            opacity: layerConfig.opacity || 1
        });
    } else if (layerConfig.type === 'vector') {
        // Create style from layer config
        const layerStyle = layerConfig.style || {};
        const fillColor = layerStyle.fill_color || layerStyle.fillColor || '#3388ff';
        const strokeColor = layerStyle.stroke_color || layerStyle.strokeColor || '#3388ff';
        const strokeWidth = layerStyle.stroke_width || layerStyle.strokeWidth || 2;
        const fillOpacity = layerStyle.fill_opacity || layerStyle.fillOpacity || 0.2;
        
        layer = new VectorLayer({
            source: new VectorSource({
                url: layerConfig.url,
                format: new GeoJSON()
            }),
            style: new Style({
                fill: new Fill({
                    color: fillColor.replace(/^#/, '') + Math.round(fillOpacity * 255).toString(16).padStart(2, '0')
                }),
                stroke: new Stroke({
                    color: strokeColor,
                    width: strokeWidth
                }),
                image: new CircleStyle({
                    radius: 6,
                    fill: new Fill({
                        color: fillColor
                    }),
                    stroke: new Stroke({
                        color: strokeColor,
                        width: strokeWidth
                    })
                })
            }),
            opacity: layerConfig.opacity || 1
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
