<template>
    <div class="analysis-panel">
        <div class="panel-header">
            <h4>Analysis Tools</h4>
            <button @click="$emit('close')" class="close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="panel-body">
            <div class="tool-section">
                <h5>Spatial Analysis</h5>
                
                <div class="tool-item">
                    <label>Buffer Analysis</label>
                    <div class="input-group">
                        <input 
                            v-model.number="bufferDistance" 
                            type="number" 
                            placeholder="Distance (meters)"
                            class="form-control"
                        >
                        <button @click="performBuffer" class="btn btn-sm btn-primary">
                            <i class="fas fa-circle-notch"></i> Buffer
                        </button>
                    </div>
                </div>

                <div class="tool-item">
                    <label>Spatial Query</label>
                    <select v-model="spatialOperation" class="form-control mb-2">
                        <option value="within">Within</option>
                        <option value="contains">Contains</option>
                        <option value="intersects">Intersects</option>
                    </select>
                    <button @click="performSpatialQuery" class="btn btn-sm btn-primary w-100">
                        <i class="fas fa-search"></i> Query Features
                    </button>
                </div>
            </div>

            <div class="tool-section">
                <h5>Attribute Query</h5>
                <div class="tool-item">
                    <div class="query-builder">
                        <div v-for="(condition, index) in queryConditions" :key="index" class="condition-row">
                            <input 
                                v-model="condition.column" 
                                placeholder="Column"
                                class="form-control form-control-sm"
                            >
                            <select v-model="condition.operator" class="form-control form-control-sm">
                                <option value="=">=</option>
                                <option value="!=">!=</option>
                                <option value=">">></option>
                                <option value="<"><</option>
                                <option value=">=">>=</option>
                                <option value="<="><=</option>
                                <option value="LIKE">LIKE</option>
                            </select>
                            <input 
                                v-model="condition.value" 
                                placeholder="Value"
                                class="form-control form-control-sm"
                            >
                            <button @click="removeCondition(index)" class="btn btn-sm btn-danger">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <button @click="addCondition" class="btn btn-sm btn-secondary mb-2">
                        <i class="fas fa-plus"></i> Add Condition
                    </button>
                    <button @click="performAttributeQuery" class="btn btn-sm btn-primary w-100">
                        <i class="fas fa-search"></i> Execute Query
                    </button>
                </div>
            </div>

            <div class="tool-section">
                <h5>Export</h5>
                <div class="tool-item">
                    <button @click="exportGeoJSON" class="btn btn-sm btn-success w-100 mb-2">
                        <i class="fas fa-download"></i> Export as GeoJSON
                    </button>
                    <button @click="exportCSV" class="btn btn-sm btn-success w-100 mb-2">
                        <i class="fas fa-file-csv"></i> Export as CSV
                    </button>
                    <button @click="exportMapConfig" class="btn btn-sm btn-info w-100 mb-2">
                        <i class="fas fa-map"></i> Export Map Config
                    </button>
                    <button @click="exportMapImage" class="btn btn-sm btn-warning w-100">
                        <i class="fas fa-image"></i> Export as Image
                    </button>
                </div>
            </div>

            <div v-if="results" class="tool-section">
                <h5>Results</h5>
                <div class="results-display">
                    <p><strong>Count:</strong> {{ results.count }}</p>
                    <div v-if="results.features" class="feature-list">
                        <div v-for="(feature, index) in results.features.slice(0, 5)" :key="index" class="feature-item">
                            Feature {{ index + 1 }}
                        </div>
                        <p v-if="results.features.length > 5" class="text-muted">
                            ... and {{ results.features.length - 5 }} more
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useMapStore } from '../../stores/mapStore';

const props = defineProps({
    selectedLayer: {
        type: Object,
        default: null
    },
    selectedGeometry: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['close', 'analysis-complete']);

const mapStore = useMapStore();
const bufferDistance = ref(100);
const spatialOperation = ref('within');
const queryConditions = ref([{ column: '', operator: '=', value: '' }]);
const results = ref(null);

const addCondition = () => {
    queryConditions.value.push({ column: '', operator: '=', value: '' });
};

const removeCondition = (index) => {
    queryConditions.value.splice(index, 1);
};

const performBuffer = async () => {
    if (!props.selectedGeometry) {
        alert('Please draw or select a geometry first');
        return;
    }

    try {
        const response = await fetch('/api/analysis/buffer', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                wkt: props.selectedGeometry,
                distance: bufferDistance.value
            })
        });

        const data = await response.json();
        
        if (data.success) {
            emit('analysis-complete', {
                type: 'buffer',
                result: data
            });
        }
    } catch (error) {
        console.error('Buffer analysis failed:', error);
        alert('Buffer analysis failed');
    }
};

const performSpatialQuery = async () => {
    if (!props.selectedLayer || !props.selectedGeometry) {
        alert('Please select a layer and draw a geometry');
        return;
    }

    try {
        const response = await fetch('/api/analysis/spatial-query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                layer_id: props.selectedLayer.id,
                operation: spatialOperation.value,
                wkt: props.selectedGeometry
            })
        });

        const data = await response.json();
        
        if (data.success) {
            results.value = data;
            emit('analysis-complete', {
                type: 'spatial-query',
                result: data
            });
        }
    } catch (error) {
        console.error('Spatial query failed:', error);
        alert('Spatial query failed');
    }
};

const performAttributeQuery = async () => {
    if (!props.selectedLayer) {
        alert('Please select a layer');
        return;
    }

    const validConditions = queryConditions.value.filter(c => c.column && c.value);
    
    if (validConditions.length === 0) {
        alert('Please add at least one valid condition');
        return;
    }

    try {
        const response = await fetch('/api/analysis/attribute-query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                layer_id: props.selectedLayer.id,
                conditions: validConditions
            })
        });

        const data = await response.json();
        
        if (data.success) {
            results.value = data;
            emit('analysis-complete', {
                type: 'attribute-query',
                result: data
            });
        }
    } catch (error) {
        console.error('Attribute query failed:', error);
        alert('Attribute query failed');
    }
};

const exportGeoJSON = () => {
    if (!props.selectedLayer) {
        alert('Please select a layer to export');
        return;
    }

    window.location.href = `/export/layer/${props.selectedLayer.id}/geojson`;
};

const exportCSV = () => {
    if (!props.selectedLayer) {
        alert('Please select a layer to export');
        return;
    }

    window.location.href = `/export/layer/${props.selectedLayer.id}/csv`;
};

const exportMapConfig = () => {
    const mapId = mapStore.currentMapId;
    
    if (!mapId) {
        alert('Please save the map first');
        return;
    }

    window.location.href = `/export/map/${mapId}/config`;
};

const exportMapImage = () => {
    const map = mapStore.map;
    
    if (!map) {
        alert('Map not available');
        return;
    }

    map.once('rendercomplete', () => {
        const mapCanvas = document.createElement('canvas');
        const size = map.getSize();
        mapCanvas.width = size[0];
        mapCanvas.height = size[1];
        const mapContext = mapCanvas.getContext('2d');
        
        Array.prototype.forEach.call(
            document.querySelectorAll('.ol-layer canvas'),
            (canvas) => {
                if (canvas.width > 0) {
                    const opacity = canvas.parentNode.style.opacity;
                    mapContext.globalAlpha = opacity === '' ? 1 : Number(opacity);
                    const transform = canvas.style.transform;
                    const matrix = transform
                        .match(/^matrix\(([^\(]*)\)$/)[1]
                        .split(',')
                        .map(Number);
                    CanvasRenderingContext2D.prototype.setTransform.apply(
                        mapContext,
                        matrix
                    );
                    mapContext.drawImage(canvas, 0, 0);
                }
            }
        );
        
        mapCanvas.toBlob((blob) => {
            const link = document.createElement('a');
            link.download = `map_${Date.now()}.png`;
            link.href = URL.createObjectURL(blob);
            link.click();
        });
    });
    
    map.renderSync();
};
</script>

<style scoped>
.analysis-panel {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 350px;
    background: white;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    z-index: 100;
    max-height: calc(100vh - 20px);
    overflow-y: auto;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid #e0e0e0;
}

.panel-header h4 {
    margin: 0;
    font-size: 16px;
}

.close-btn {
    border: none;
    background: none;
    cursor: pointer;
    padding: 5px;
    color: #666;
}

.close-btn:hover {
    color: #000;
}

.panel-body {
    padding: 15px;
}

.tool-section {
    margin-bottom: 20px;
}

.tool-section h5 {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 10px;
    color: #333;
}

.tool-item {
    margin-bottom: 15px;
}

.tool-item label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 5px;
    color: #555;
}

.input-group {
    display: flex;
    gap: 5px;
}

.input-group input {
    flex: 1;
}

.form-control {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
}

.form-control-sm {
    padding: 4px 8px;
    font-size: 12px;
}

.btn {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-sm {
    padding: 4px 8px;
    font-size: 12px;
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

.btn-success {
    background: #28a745;
    color: white;
}

.btn-success:hover {
    background: #218838;
}

.btn-info {
    background: #17a2b8;
    color: white;
}

.btn-info:hover {
    background: #138496;
}

.btn-warning {
    background: #ffc107;
    color: #212529;
}

.btn-warning:hover {
    background: #e0a800;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

.w-100 {
    width: 100%;
}

.mb-2 {
    margin-bottom: 8px;
}

.query-builder {
    margin-bottom: 10px;
}

.condition-row {
    display: grid;
    grid-template-columns: 1fr auto 1fr auto;
    gap: 5px;
    margin-bottom: 8px;
}

.results-display {
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
    font-size: 13px;
}

.feature-list {
    margin-top: 10px;
}

.feature-item {
    padding: 5px;
    background: white;
    margin-bottom: 5px;
    border-radius: 3px;
    font-size: 12px;
}

.text-muted {
    color: #6c757d;
    font-size: 12px;
    font-style: italic;
}
</style>
