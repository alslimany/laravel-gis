<template>
    <div class="map-builder">
        <div class="map-builder-header">
            <h3>Map Builder</h3>
            <div class="header-actions">
                <button @click="toggleAnalysis" class="btn btn-info btn-sm">
                    <i class="fas fa-chart-area"></i> Analysis
                </button>
                <button @click="saveMap" class="btn btn-primary btn-sm">
                    <i class="fas fa-save"></i> Save Map
                </button>
                <button @click="exportMap" class="btn btn-secondary btn-sm">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>

        <div class="map-builder-content">
            <div class="left-panel">
                <LayerPanel />
            </div>

            <div class="map-container">
                <MapComponent ref="mapComponent" />
                <ToolPanel :map="mapInstance" @tool-selected="handleToolSelection" />
                <AnalysisPanel 
                    v-if="showAnalysis" 
                    :selected-layer="selectedLayer"
                    :selected-geometry="drawnGeometry"
                    @close="showAnalysis = false"
                    @analysis-complete="handleAnalysisComplete"
                />
            </div>

            <div class="right-panel">
                <StyleEditor v-if="selectedLayer" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useMapStore } from '../../stores/mapStore';
import MapComponent from './MapComponent.vue';
import LayerPanel from './LayerPanel.vue';
import ToolPanel from './ToolPanel.vue';
import StyleEditor from './StyleEditor.vue';
import AnalysisPanel from './AnalysisPanel.vue';
import Draw from 'ol/interaction/Draw';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import { Style, Stroke, Fill, Circle as CircleStyle } from 'ol/style';

const mapStore = useMapStore();
const selectedLayer = computed(() => mapStore.selectedLayer);
const mapComponent = ref(null);
const mapInstance = computed(() => mapStore.map);
const showAnalysis = ref(false);
const drawnGeometry = ref(null);
const currentTool = ref(null);
let currentDraw = null;
let drawLayer = null;

const toggleAnalysis = () => {
    showAnalysis.value = !showAnalysis.value;
};

const handleToolSelection = (toolId) => {
    currentTool.value = toolId;
    console.log('Tool selected in MapBuilder:', toolId);
    
    // Remove existing draw interaction
    if (currentDraw && mapInstance.value) {
        mapInstance.value.removeInteraction(currentDraw);
        currentDraw = null;
    }
    
    // Handle drawing tools
    if (toolId && toolId.startsWith('draw-') && mapInstance.value) {
        initializeDrawing(toolId);
    }
};

const initializeDrawing = (toolId) => {
    const map = mapInstance.value;
    if (!map) {
        console.warn('Map instance not available');
        return;
    }

    // Create draw layer if it doesn't exist
    if (!drawLayer) {
        const source = new VectorSource();
        drawLayer = new VectorLayer({
            source: source,
            style: new Style({
                fill: new Fill({
                    color: 'rgba(0, 123, 255, 0.2)'
                }),
                stroke: new Stroke({
                    color: '#007bff',
                    width: 2
                }),
                image: new CircleStyle({
                    radius: 7,
                    fill: new Fill({
                        color: '#007bff'
                    })
                })
            })
        });
        map.addLayer(drawLayer);
    }

    // Determine geometry type
    let geometryType;
    switch (toolId) {
        case 'draw-point':
            geometryType = 'Point';
            break;
        case 'draw-line':
            geometryType = 'LineString';
            break;
        case 'draw-polygon':
            geometryType = 'Polygon';
            break;
        default:
            return;
    }

    // Create draw interaction
    currentDraw = new Draw({
        source: drawLayer.getSource(),
        type: geometryType
    });

    // Handle draw end event
    currentDraw.on('drawend', (event) => {
        drawnGeometry.value = event.feature.getGeometry();
        console.log('Feature drawn:', event.feature);
    });

    map.addInteraction(currentDraw);
};

const handleAnalysisComplete = (result) => {
    console.log('Analysis complete:', result);
    // Handle analysis results - could display on map, show in panel, etc.
};

const saveMap = async () => {
    try {
        const mapData = {
            name: 'My Map',
            viewport: mapStore.viewport,
            basemap: mapStore.basemap,
            layers: mapStore.layers
        };

        const response = await window.axios.post('/api/maps', mapData);
        alert('Map saved successfully!');
    } catch (error) {
        console.error('Error saving map:', error);
        alert('Failed to save map');
    }
};

const exportMap = () => {
    const mapData = {
        viewport: mapStore.viewport,
        basemap: mapStore.basemap,
        layers: mapStore.layers
    };
    
    const blob = new Blob([JSON.stringify(mapData, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'map-config.json';
    a.click();
    URL.revokeObjectURL(url);
};
</script>

<style scoped>
.map-builder {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 100px);
}

.map-builder-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: white;
    border-bottom: 1px solid #ddd;
}

.map-builder-header h3 {
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

.map-builder-content {
    display: flex;
    flex: 1;
    overflow: hidden;
}

.left-panel {
    width: 300px;
    background: white;
    border-right: 1px solid #ddd;
    overflow-y: auto;
}

.map-container {
    flex: 1;
    position: relative;
    background: #f5f5f5;
}

.right-panel {
    width: 300px;
    background: white;
    border-left: 1px solid #ddd;
    overflow-y: auto;
}
</style>
