<template>
    <div class="style-editor">
        <div class="panel-header">
            <h5>Style Editor</h5>
        </div>

        <div v-if="selectedLayer" class="style-form">
            <div class="mb-3">
                <label class="form-label">Layer Name:</label>
                <input
                    v-model="layerStyle.name"
                    type="text"
                    class="form-control form-control-sm"
                    disabled
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Opacity:</label>
                <input
                    v-model.number="layerStyle.opacity"
                    type="range"
                    min="0"
                    max="1"
                    step="0.1"
                    class="form-range"
                    @input="updateStyle"
                >
                <small class="text-muted">{{ Math.round(layerStyle.opacity * 100) }}%</small>
            </div>

            <div v-if="selectedLayer.type === 'vector'" class="vector-styles">
                <h6>Vector Style</h6>

                <div class="mb-3">
                    <label class="form-label">Fill Color:</label>
                    <input
                        v-model="layerStyle.fillColor"
                        type="color"
                        class="form-control form-control-color"
                        @input="updateStyle"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">Fill Opacity:</label>
                    <input
                        v-model.number="layerStyle.fillOpacity"
                        type="range"
                        min="0"
                        max="1"
                        step="0.1"
                        class="form-range"
                        @input="updateStyle"
                    >
                    <small class="text-muted">{{ Math.round(layerStyle.fillOpacity * 100) }}%</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Stroke Color:</label>
                    <input
                        v-model="layerStyle.strokeColor"
                        type="color"
                        class="form-control form-control-color"
                        @input="updateStyle"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">Stroke Width:</label>
                    <input
                        v-model.number="layerStyle.strokeWidth"
                        type="number"
                        min="1"
                        max="10"
                        class="form-control form-control-sm"
                        @input="updateStyle"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">Stroke Opacity:</label>
                    <input
                        v-model.number="layerStyle.strokeOpacity"
                        type="range"
                        min="0"
                        max="1"
                        step="0.1"
                        class="form-range"
                        @input="updateStyle"
                    >
                    <small class="text-muted">{{ Math.round(layerStyle.strokeOpacity * 100) }}%</small>
                </div>
            </div>

            <div class="mb-3">
                <button @click="applyStyle" class="btn btn-primary btn-sm w-100">
                    <i class="fas fa-check"></i> Apply Style
                </button>
            </div>

            <div class="mb-3">
                <button @click="resetStyle" class="btn btn-secondary btn-sm w-100">
                    <i class="fas fa-undo"></i> Reset to Default
                </button>
            </div>
        </div>

        <div v-else class="empty-state">
            <i class="fas fa-palette fa-3x text-muted"></i>
            <p>Select a layer to edit its style</p>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useMapStore } from '../../stores/mapStore';

const mapStore = useMapStore();
const selectedLayer = computed(() => {
    return mapStore.getLayerById(mapStore.selectedLayer);
});

const defaultStyle = {
    opacity: 1,
    fillColor: '#3388ff',
    fillOpacity: 0.6,
    strokeColor: '#3388ff',
    strokeWidth: 2,
    strokeOpacity: 1
};

const layerStyle = ref({ ...defaultStyle });

watch(selectedLayer, (newLayer) => {
    if (newLayer) {
        layerStyle.value = {
            name: newLayer.name,
            opacity: newLayer.opacity || 1,
            fillColor: newLayer.style?.fillColor || defaultStyle.fillColor,
            fillOpacity: newLayer.style?.fillOpacity || defaultStyle.fillOpacity,
            strokeColor: newLayer.style?.strokeColor || defaultStyle.strokeColor,
            strokeWidth: newLayer.style?.strokeWidth || defaultStyle.strokeWidth,
            strokeOpacity: newLayer.style?.strokeOpacity || defaultStyle.strokeOpacity
        };
    }
}, { immediate: true });

const updateStyle = () => {
    // Style is updated reactively
};

const applyStyle = () => {
    if (selectedLayer.value) {
        const style = {
            fillColor: layerStyle.value.fillColor,
            fillOpacity: layerStyle.value.fillOpacity,
            strokeColor: layerStyle.value.strokeColor,
            strokeWidth: layerStyle.value.strokeWidth,
            strokeOpacity: layerStyle.value.strokeOpacity
        };
        
        mapStore.updateLayerStyle(selectedLayer.value.id, style);
        alert('Style applied successfully!');
    }
};

const resetStyle = () => {
    layerStyle.value = { ...defaultStyle, name: selectedLayer.value.name };
    applyStyle();
};
</script>

<style scoped>
.style-editor {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.panel-header {
    padding: 1rem;
    border-bottom: 1px solid #ddd;
}

.panel-header h5 {
    margin: 0;
}

.style-form {
    padding: 1rem;
    overflow-y: auto;
}

.empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}

.empty-state i {
    margin-bottom: 1rem;
}

.form-label {
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}

.form-control-color {
    width: 100%;
    height: 40px;
}

.vector-styles h6 {
    font-size: 0.9rem;
    font-weight: 600;
    margin-top: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #ddd;
}
</style>
