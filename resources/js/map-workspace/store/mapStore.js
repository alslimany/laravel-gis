import { create } from 'zustand';

const defaultViewport = {
    center: [0, 0],
    zoom: 2,
    rotation: 0,
};

const availableBasemaps = [
    { id: 'osm', name: 'Street', type: 'tile' },
    { id: 'imagery', name: 'Satellite', type: 'tile' },
];

function supportedBasemap(id) {
    return availableBasemaps.some((item) => item.id === id) ? id : 'osm';
}

export const useMapStore = create((set, get) => ({
    map: null,
    mapId: null,
    mapName: null,
    layers: [],
    selectedLayer: null,
    viewport: { ...defaultViewport },
    basemap: 'osm',
    availableBasemaps,

    // Active map tool (measure-distance, measure-area, identify, etc.)
    activeTool: null,

    // Overlay / tool result state consumed by MapView and MapWorkspace
    analysisResults: null,
    identifyResult: null,
    measureResult: null,

    setMap: (map) => set({ map }),

    setActiveTool: (toolId) => set({ activeTool: toolId }),

    setAnalysisResults: (analysisResults) => set({ analysisResults }),

    setIdentifyResult: (identifyResult) => set({ identifyResult }),

    setMeasureResult: (measureResult) => set({ measureResult }),

    clearAnalysisResults: () => set({ analysisResults: null }),

    hydrateFromInitialData: (data) => {
        if (!data) {
            return;
        }

        set({
            mapId: data.id ?? null,
            mapName: data.name ?? null,
            viewport: data.viewport
                ? { ...defaultViewport, ...data.viewport }
                : { ...defaultViewport },
            basemap: supportedBasemap(data.basemap),
            layers: Array.isArray(data.layers) ? data.layers : [],
        });
    },

    addLayer: (layer) => set((state) => ({ layers: [...state.layers, layer] })),

    removeLayer: (layerId) =>
        set((state) => ({
            layers: state.layers.filter((layer) => layer.id !== layerId),
            selectedLayer: state.selectedLayer === layerId ? null : state.selectedLayer,
        })),

    updateLayerOrder: (layers) => set({ layers }),

    selectLayer: (layerId) => set({ selectedLayer: layerId }),

    updateViewport: (viewport) =>
        set((state) => ({
            viewport: { ...state.viewport, ...viewport },
        })),

    setBasemap: (basemapId) => set({ basemap: supportedBasemap(basemapId) }),

    updateLayerStyle: (layerId, style) =>
        set((state) => ({
            layers: state.layers.map((layer) => {
                if (layer.id !== layerId) {
                    return layer;
                }

                // Accept either a flat style object or a full style_config payload.
                const isConfig =
                    style &&
                    (style.renderer ||
                        style.symbol ||
                        style.labels ||
                        style.legend ||
                        style.classes ||
                        style.uniqueValues);

                if (isConfig) {
                    return {
                        ...layer,
                        style_config: style,
                        style: {
                            ...(layer.style || {}),
                            ...(style.symbol || style),
                        },
                    };
                }

                return {
                    ...layer,
                    style,
                    style_config: {
                        ...(layer.style_config || {}),
                        renderer: layer.style_config?.renderer || 'simple',
                        symbol: { ...(layer.style_config?.symbol || {}), ...style },
                    },
                };
            }),
        })),

    updateLayer: (layerId, patch) =>
        set((state) => ({
            layers: state.layers.map((layer) =>
                layer.id === layerId ? { ...layer, ...patch } : layer
            ),
        })),

    toggleLayerVisibility: (layerId) =>
        set((state) => ({
            layers: state.layers.map((layer) =>
                layer.id === layerId ? { ...layer, visible: !layer.visible } : layer
            ),
        })),

    getLayerById: (layerId) => get().layers.find((layer) => layer.id === layerId),

    getVisibleLayers: () => get().layers.filter((layer) => layer.visible),
}));
