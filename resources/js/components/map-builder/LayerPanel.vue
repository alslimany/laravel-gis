<template>
    <div class="layer-panel">
        <div class="panel-header">
            <h5>Layers</h5>
            <button @click="showAddLayerModal" class="btn btn-sm btn-primary">
                <i class="fas fa-plus"></i> Add Layer
            </button>
        </div>

        <div class="basemap-selector">
            <label>Base Map:</label>
            <select v-model="selectedBasemap" @change="changeBasemap" class="form-select form-select-sm">
                <option v-for="basemap in availableBasemaps" :key="basemap.id" :value="basemap.id">
                    {{ basemap.name }}
                </option>
            </select>
        </div>

        <div class="layers-list">
            <div v-if="layers.length === 0" class="empty-state">
                <i class="fas fa-layer-group fa-3x text-muted"></i>
                <p>No layers added yet</p>
            </div>

            <draggable
                v-model="layersList"
                @end="onLayerReorder"
                item-key="id"
                class="layer-items"
            >
                <template #item="{ element }">
                    <div
                        class="layer-item"
                        :class="{ 'active': element.id === selectedLayerId }"
                        @click="selectLayer(element.id)"
                    >
                        <div class="layer-info">
                            <button
                                @click.stop="toggleVisibility(element.id)"
                                class="btn btn-sm btn-link visibility-btn"
                            >
                                <i :class="element.visible ? 'fas fa-eye' : 'fas fa-eye-slash'"></i>
                            </button>
                            <span class="layer-name">{{ element.name }}</span>
                        </div>
                        <div class="layer-actions">
                            <button
                                @click.stop="removeLayer(element.id)"
                                class="btn btn-sm btn-link text-danger"
                            >
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </template>
            </draggable>
        </div>

        <!-- Add Layer Modal -->
        <div v-if="showModal" class="modal-overlay" @click="closeModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h5>Add Layer</h5>
                    <button @click="closeModal" class="btn-close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Layer Source:</label>
                        <select v-model="layerSource" @change="onSourceChange" class="form-select">
                            <option value="custom">Custom URL</option>
                            <option value="geoserver">From GeoServer</option>
                        </select>
                    </div>

                    <div v-if="layerSource === 'geoserver'" class="mb-3">
                        <label class="form-label">Published Layers:</label>
                        <select v-model="selectedGeoServerLayer" @change="onGeoServerLayerSelect" class="form-select">
                            <option value="">Select a published layer...</option>
                            <option v-for="layer in publishedLayers" :key="layer.id" :value="layer.id">
                                {{ layer.name }} ({{ layer.geometry_type }})
                            </option>
                        </select>
                        <small class="text-muted">Only published layers are shown</small>
                    </div>

                    <div v-if="layerSource === 'custom'">
                        <div class="mb-3">
                            <label class="form-label">Layer Type:</label>
                            <select v-model="newLayer.type" class="form-select">
                                <option value="wms">WMS Layer</option>
                                <option value="wfs">WFS Layer</option>
                                <option value="vector">Vector Layer (GeoJSON)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Layer Name:</label>
                            <input v-model="newLayer.name" type="text" class="form-control" placeholder="Enter layer name">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">URL:</label>
                            <input v-model="newLayer.url" type="text" class="form-control" placeholder="Enter layer URL">
                        </div>

                        <div v-if="newLayer.type === 'wms'" class="mb-3">
                            <label class="form-label">Layer Names:</label>
                            <input v-model="newLayer.layers" type="text" class="form-control" placeholder="e.g., workspace:layername">
                        </div>
                    </div>

                    <div v-if="layerSource === 'geoserver' && selectedGeoServerLayer">
                        <div class="mb-3">
                            <label class="form-label">Layer Name:</label>
                            <input v-model="newLayer.name" type="text" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">GeoServer URL:</label>
                            <input v-model="newLayer.url" type="text" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Layer:</label>
                            <input v-model="newLayer.layers" type="text" class="form-control" readonly>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="closeModal" class="btn btn-secondary">Cancel</button>
                    <button @click="addLayer" class="btn btn-primary">Add Layer</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useMapStore } from '../../stores/mapStore';
import draggable from 'vuedraggable';

const mapStore = useMapStore();
const showModal = ref(false);
const layerSource = ref('custom');
const selectedGeoServerLayer = ref('');
const publishedLayers = ref([]);
const newLayer = ref({
    type: 'wms',
    name: '',
    url: '',
    layers: ''
});

const layers = computed(() => mapStore.layers);
const layersList = computed({
    get: () => mapStore.layers,
    set: (value) => mapStore.updateLayerOrder(value)
});
const selectedLayerId = computed(() => mapStore.selectedLayer);
const availableBasemaps = computed(() => mapStore.availableBasemaps);
const selectedBasemap = ref(mapStore.basemap);

// Load published layers when component mounts
onMounted(async () => {
    await loadPublishedLayers();
});

const selectLayer = (layerId) => {
    mapStore.selectLayer(layerId);
};

const toggleVisibility = (layerId) => {
    mapStore.toggleLayerVisibility(layerId);
};

const removeLayer = (layerId) => {
    if (confirm('Are you sure you want to remove this layer?')) {
        mapStore.removeLayer(layerId);
    }
};

const onLayerReorder = () => {
    // Layer order is already updated via v-model
};

const changeBasemap = () => {
    mapStore.setBasemap(selectedBasemap.value);
};

const showAddLayerModal = () => {
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    layerSource.value = 'custom';
    selectedGeoServerLayer.value = '';
    newLayer.value = {
        type: 'wms',
        name: '',
        url: '',
        layers: ''
    };
};

const addLayer = () => {
    if (!newLayer.value.name || !newLayer.value.url) {
        alert('Please fill in all required fields');
        return;
    }

    const layer = {
        id: Date.now().toString(),
        name: newLayer.value.name,
        type: newLayer.value.type,
        url: newLayer.value.url,
        layers: newLayer.value.layers,
        visible: true,
        opacity: 1,
        style: {}
    };

    mapStore.addLayer(layer);
    closeModal();
};

const loadPublishedLayers = async () => {
    try {
        // Fetch published layers from the backend
        const response = await window.axios.get('/layers');
        if (response.data && response.data.data) {
            publishedLayers.value = response.data.data.filter(layer => layer.published);
        }
    } catch (error) {
        console.error('Error loading published layers:', error);
    }
};

const onSourceChange = () => {
    if (layerSource.value === 'geoserver') {
        loadPublishedLayers();
    }
};

const onGeoServerLayerSelect = () => {
    const layer = publishedLayers.value.find(l => l.id === parseInt(selectedGeoServerLayer.value));
    if (layer) {
        // Get GeoServer base URL from config or use default
        const geoserverUrl = import.meta.env.VITE_GEOSERVER_URL || 'http://localhost:8080/geoserver';
        
        newLayer.value = {
            type: 'wms',
            name: layer.name,
            url: `${geoserverUrl}/wms`,
            layers: layer.geoserver_layer_name || `${layer.geoserver_workspace}:${layer.table_name}`
        };
    }
};
</script>

<style scoped>
.layer-panel {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.panel-header {
    padding: 1rem;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-header h5 {
    margin: 0;
}

.basemap-selector {
    padding: 1rem;
    border-bottom: 1px solid #ddd;
}

.basemap-selector label {
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}

.layers-list {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}

.empty-state i {
    margin-bottom: 1rem;
}

.layer-items {
    list-style: none;
    padding: 0;
    margin: 0;
}

.layer-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.layer-item:hover {
    background: #e9ecef;
}

.layer-item.active {
    background: #007bff;
    color: white;
}

.layer-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.visibility-btn {
    padding: 0.25rem 0.5rem;
}

.layer-name {
    font-weight: 500;
}

.layer-actions {
    display: flex;
    gap: 0.25rem;
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
</style>
