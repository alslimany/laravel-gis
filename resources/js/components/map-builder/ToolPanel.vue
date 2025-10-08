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

const activeTool = ref(null);

const tools = [
    { id: 'pan', name: 'Pan', icon: 'fas fa-hand-paper' },
    { id: 'select', name: 'Select', icon: 'fas fa-mouse-pointer' },
    { id: 'measure-distance', name: 'Measure Distance', icon: 'fas fa-ruler' },
    { id: 'measure-area', name: 'Measure Area', icon: 'fas fa-draw-polygon' },
    { id: 'zoom-in', name: 'Zoom In', icon: 'fas fa-search-plus' },
    { id: 'zoom-out', name: 'Zoom Out', icon: 'fas fa-search-minus' },
    { id: 'zoom-extent', name: 'Zoom to Extent', icon: 'fas fa-expand' }
];

const selectTool = (toolId) => {
    if (activeTool.value === toolId) {
        activeTool.value = null;
    } else {
        activeTool.value = toolId;
        executeTool(toolId);
    }
};

const executeTool = (toolId) => {
    // Tool execution logic would go here
    console.log('Tool selected:', toolId);
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
