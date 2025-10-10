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

        <!-- Feature Properties Dialog -->
        <div v-if="showPropertiesDialog" class="modal-overlay" @click="closePropertiesDialog">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h5>Feature Properties</h5>
                    <button @click="closePropertiesDialog" class="btn-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name:</label>
                        <input v-model="featureProperties.name" type="text" class="form-control" placeholder="Enter feature name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description:</label>
                        <textarea v-model="featureProperties.description" class="form-control" rows="3" placeholder="Enter description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type:</label>
                        <input v-model="featureProperties.type" type="text" class="form-control" placeholder="Enter feature type (e.g., building, road, park)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes:</label>
                        <textarea v-model="featureProperties.notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="closePropertiesDialog" class="btn btn-secondary">Cancel</button>
                    <button @click="saveFeatureProperties" class="btn btn-primary">Save Properties</button>
                </div>
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
const showPropertiesDialog = ref(false);
const currentFeature = ref(null);
const featureProperties = ref({
    name: '',
    description: '',
    type: '',
    notes: ''
});
let currentDraw = null;
let drawLayer = null;

const toggleAnalysis = () => {
    showAnalysis.value = !showAnalysis.value;
};

const handleToolSelection = (toolId) => {
    console.log('Tool selected in MapBuilder:', toolId);
    
    // Remove existing draw interaction FIRST before updating currentTool
    if (currentDraw && mapInstance.value) {
        mapInstance.value.removeInteraction(currentDraw);
        currentDraw = null;
    }
    
    // Update current tool AFTER removing previous interaction
    currentTool.value = toolId;
    
    // Handle drawing tools - only if toolId is not null
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
        currentFeature.value = event.feature;
        console.log('Feature drawn:', event.feature);
        
        // Show properties dialog
        showPropertiesDialog.value = true;
    });

    map.addInteraction(currentDraw);
};

const saveFeatureProperties = () => {
    if (currentFeature.value) {
        // Set properties on the feature
        currentFeature.value.setProperties({
            name: featureProperties.value.name,
            description: featureProperties.value.description,
            type: featureProperties.value.type,
            notes: featureProperties.value.notes
        });
        
        console.log('Feature properties saved:', featureProperties.value);
    }
    
    // Reset and close dialog
    closePropertiesDialog();
};

const closePropertiesDialog = () => {
    showPropertiesDialog.value = false;
    currentFeature.value = null;
    featureProperties.value = {
        name: '',
        description: '',
        type: '',
        notes: ''
    };
};

const handleAnalysisComplete = (result) => {
    console.log('Analysis complete:', result);
    // Handle analysis results - could display on map, show in panel, etc.
};

const saveMap = async () => {
    try {
        // Prompt for map name if not set
        const mapName = prompt('Enter a name for your map:', 'My Map');
        if (!mapName) {
            return; // User cancelled
        }

        const mapData = {
            name: mapName,
            description: '',
            viewport: mapStore.viewport,
            basemap: mapStore.basemap,
            layers: mapStore.layers,
            is_public: false
        };

        console.log('Saving map with data:', mapData);
        const response = await window.axios.post('/api/maps', mapData);
        console.log('Map saved successfully:', response.data);
        alert('Map saved successfully! Redirecting...');
        
        // Redirect to the map view
        if (response.data.map && response.data.map.id) {
            window.location.href = `/maps/${response.data.map.id}`;
        }
    } catch (error) {
        console.error('Error saving map:', error);
        if (error.response) {
            console.error('Response data:', error.response.data);
            console.error('Response status:', error.response.status);
            alert(`Failed to save map: ${error.response.data.message || error.response.statusText}`);
        } else if (error.request) {
            console.error('No response received:', error.request);
            alert('Failed to save map: No response from server. Please check your connection.');
        } else {
            console.error('Error message:', error.message);
            alert(`Failed to save map: ${error.message}`);
        }
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

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1rem;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h5 {
    margin: 0;
}

.btn-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    color: #000;
    opacity: 0.5;
}

.btn-close:hover {
    opacity: 1;
}

.modal-body {
    padding: 1rem;
}

.modal-footer {
    padding: 1rem;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.form-label {
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.mb-3 {
    margin-bottom: 1rem;
}

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}
</style>
