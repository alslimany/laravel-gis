<template>
    <div class="tool-panel">
        <div class="tool-group">
            <button
                v-for="tool in tools"
                :key="tool.id"
                @click="selectTool(tool.id)"
                :class="['tool-btn', { active: activeTool === tool.id }]"
                :title="tool.name"
            >
                <i :class="tool.icon"></i>
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useMapStore } from '../../stores/mapStore';

const props = defineProps({
    map: {
        type: Object,
        required: false
    }
});

const emit = defineEmits(['tool-selected']);

const mapStore = useMapStore();
const activeTool = ref(null);

const tools = [
    { id: 'pan', name: 'Pan', icon: 'fas fa-hand-paper' },
    { id: 'select', name: 'Select', icon: 'fas fa-mouse-pointer' },
    { id: 'draw-point', name: 'Draw Point', icon: 'fas fa-map-pin' },
    { id: 'draw-line', name: 'Draw Line', icon: 'fas fa-minus' },
    { id: 'draw-polygon', name: 'Draw Polygon', icon: 'fas fa-draw-polygon' },
    { id: 'measure-distance', name: 'Measure Distance', icon: 'fas fa-ruler' },
    { id: 'measure-area', name: 'Measure Area', icon: 'fas fa-vector-square' },
    { id: 'identify', name: 'Identify/Coordinates', icon: 'fas fa-info-circle' },
    { id: 'zoom-in', name: 'Zoom In', icon: 'fas fa-search-plus' },
    { id: 'zoom-out', name: 'Zoom Out', icon: 'fas fa-search-minus' },
    { id: 'zoom-extent', name: 'Zoom to Extent', icon: 'fas fa-expand' }
];

const selectTool = (toolId) => {
    if (activeTool.value === toolId) {
        activeTool.value = null;
        emit('tool-selected', null);
    } else {
        activeTool.value = toolId;
        executeTool(toolId);
        emit('tool-selected', toolId);
    }
};

const executeTool = (toolId) => {
    const map = props.map || mapStore.map;
    
    if (!map) {
        console.warn('Map instance not available');
        return;
    }

    // Handle different tools
    switch (toolId) {
        case 'zoom-in':
            const view = map.getView();
            view.animate({
                zoom: view.getZoom() + 1,
                duration: 250
            });
            activeTool.value = null;
            break;
            
        case 'zoom-out':
            const viewOut = map.getView();
            viewOut.animate({
                zoom: viewOut.getZoom() - 1,
                duration: 250
            });
            activeTool.value = null;
            break;
            
        case 'zoom-extent':
            const extent = map.getView().calculateExtent(map.getSize());
            map.getView().fit(extent, {
                duration: 250,
                padding: [50, 50, 50, 50]
            });
            activeTool.value = null;
            break;
            
        default:
            // Other tools are handled by parent component
            console.log('Tool selected:', toolId);
    }
};
</script>

<style scoped>
.tool-panel {
    position: absolute;
    top: 10px;
    left: 10px;
    background: white;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    z-index: 100;
}

.tool-group {
    display: flex;
    flex-direction: column;
    padding: 5px;
}

.tool-btn {
    width: 40px;
    height: 40px;
    border: none;
    background: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.tool-btn:hover {
    background: #f0f0f0;
}

.tool-btn.active {
    background: #007bff;
    color: white;
}

.tool-btn i {
    font-size: 18px;
}
</style>
