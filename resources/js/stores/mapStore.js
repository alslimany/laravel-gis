import { defineStore } from 'pinia';

export const useMapStore = defineStore('map', {
    state: () => ({
        map: null,
        layers: [],
        selectedLayer: null,
        viewport: {
            center: [0, 0],
            zoom: 2,
            rotation: 0
        },
        basemap: 'osm',
        availableBasemaps: [
            { id: 'osm', name: 'OpenStreetMap', type: 'tile' },
            { id: 'satellite', name: 'Satellite', type: 'tile' },
            { id: 'terrain', name: 'Terrain', type: 'tile' },
            { id: 'light', name: 'Light', type: 'tile' },
            { id: 'dark', name: 'Dark', type: 'tile' },
            { id: 'bing-aerial', name: 'Bing Aerial', type: 'tile' },
            { id: 'bing-road', name: 'Bing Road', type: 'tile' }
        ]
    }),

    actions: {
        setMap(map) {
            this.map = map;
        },

        addLayer(layer) {
            this.layers.push(layer);
        },

        removeLayer(layerId) {
            const index = this.layers.findIndex(l => l.id === layerId);
            if (index !== -1) {
                this.layers.splice(index, 1);
            }
        },

        updateLayerOrder(layers) {
            this.layers = layers;
        },

        selectLayer(layerId) {
            this.selectedLayer = layerId;
        },

        updateViewport(viewport) {
            this.viewport = { ...this.viewport, ...viewport };
        },

        setBasemap(basemapId) {
            this.basemap = basemapId;
        },

        updateLayerStyle(layerId, style) {
            const layer = this.layers.find(l => l.id === layerId);
            if (layer) {
                layer.style = style;
            }
        },

        toggleLayerVisibility(layerId) {
            const layer = this.layers.find(l => l.id === layerId);
            if (layer) {
                layer.visible = !layer.visible;
            }
        }
    },

    getters: {
        getLayerById: (state) => (layerId) => {
            return state.layers.find(l => l.id === layerId);
        },

        visibleLayers: (state) => {
            return state.layers.filter(l => l.visible);
        }
    }
});
