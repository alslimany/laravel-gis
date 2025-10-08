<template>
    <div class="map-builder">
        <div class="map-builder-header">
            <h3>Map Builder</h3>
            <div class="header-actions">
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
                <MapComponent />
                <ToolPanel />
            </div>

            <div class="right-panel">
                <StyleEditor v-if="selectedLayer" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useMapStore } from '../../stores/mapStore';
import MapComponent from './MapComponent.vue';
import LayerPanel from './LayerPanel.vue';
import ToolPanel from './ToolPanel.vue';
import StyleEditor from './StyleEditor.vue';

const mapStore = useMapStore();
const selectedLayer = computed(() => mapStore.selectedLayer);

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
