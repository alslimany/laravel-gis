import { useEffect, useRef } from 'react';
import Map from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import VectorTileLayer from 'ol/layer/VectorTile';
import HeatmapLayer from 'ol/layer/Heatmap';
import OSM from 'ol/source/OSM';
import XYZ from 'ol/source/XYZ';
import VectorSource from 'ol/source/Vector';
import VectorTileSource from 'ol/source/VectorTile';
import TileWMS from 'ol/source/TileWMS';
import GeoJSON from 'ol/format/GeoJSON';
import MVT from 'ol/format/MVT';
import { fromLonLat, toLonLat } from 'ol/proj';
import { defaults as defaultControls, FullScreen, ScaleLine, ZoomSlider } from 'ol/control';
import Select from 'ol/interaction/Select';
import { click } from 'ol/events/condition';
import Overlay from 'ol/Overlay';
import { Style, Stroke, Fill, Circle as CircleStyle } from 'ol/style';
import { useMapStore } from '../store/mapStore';
import {
    createStyleFunction,
    getHeatmapOptions,
    isHeatmapRenderer,
    normalizeStyleConfig,
} from '../utils/styleRenderers';
import 'ol/ol.css';
import { attachTileCache } from '../cache/tileCache';

const ANALYSIS_LAYER_ID = '__analysis_results__';

const cachedTileLayer = (mapType, source) =>
    new TileLayer({ source: attachTileCache(source, mapType) });

const createBaseLayer = (basemapId) => {
    const mapboxToken = import.meta.env.VITE_MAPBOX_TOKEN || '';

    switch (basemapId) {
        case 'imagery':
            return cachedTileLayer(
                'imagery',
                new XYZ({
                    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                    maxZoom: 19,
                    attributions:
                        'Tiles © Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
                }),
            );
        case 'satellite':
        case 'mapbox-satellite':
            return cachedTileLayer(
                'satellite',
                new XYZ({
                    url: `https://api.mapbox.com/styles/v1/mapbox/satellite-v9/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    tileSize: 512,
                    maxZoom: 19,
                    attributions:
                        '© <a href="https://www.mapbox.com/about/maps/">Mapbox</a> © <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                }),
            );
        case 'mapbox-streets':
            return cachedTileLayer(
                'mapbox-streets',
                new XYZ({
                    url: `https://api.mapbox.com/styles/v1/mapbox/streets-v11/tiles/{z}/{x}/{y}?access_token=${mapboxToken}`,
                    tileSize: 512,
                    maxZoom: 19,
                    attributions:
                        '© <a href="https://www.mapbox.com/about/maps/">Mapbox</a> © <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                }),
            );
        case 'terrain':
            return cachedTileLayer(
                'terrain',
                new OSM({
                    url: 'https://{a-c}.tile.opentopomap.org/{z}/{x}/{y}.png',
                    attributions:
                        'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="http://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a>',
                }),
            );
        case 'dark':
            return cachedTileLayer(
                'dark',
                new OSM({
                    url: 'https://{a-c}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                    attributions:
                        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
                }),
            );
        case 'light':
            return cachedTileLayer(
                'light',
                new OSM({
                    url: 'https://{a-c}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                    attributions:
                        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
                }),
            );
        case 'osm':
        default:
            return cachedTileLayer('osm', new OSM());
    }
};

const ANALYSIS_STYLE = new Style({
    fill: new Fill({ color: 'rgba(255, 152, 0, 0.25)' }),
    stroke: new Stroke({ color: '#ff9800', width: 2 }),
    image: new CircleStyle({
        radius: 7,
        fill: new Fill({ color: '#ff9800' }),
        stroke: new Stroke({ color: '#fff', width: 2 }),
    }),
});

function buildMvtUrl(layerConfig) {
    let base =
        layerConfig.mvtUrl ||
        layerConfig.mvt_url ||
        `/api/layers/${layerConfig.id}/tiles/{z}/{x}/{y}.mvt`;

    const params = new URLSearchParams();
    const timeFrom = layerConfig.timeFrom || layerConfig.time_from;
    const timeTo = layerConfig.timeTo || layerConfig.time_to;
    const timeField = layerConfig.timeField || layerConfig.time_field;

    if (timeField && (timeFrom || timeTo)) {
        params.set('time_field', timeField);
        if (timeFrom) {
            params.set('time_from', timeFrom);
        }
        if (timeTo) {
            params.set('time_to', timeTo);
        }
    }

    const qs = params.toString();
    if (!qs) {
        return base;
    }

    // Append query string without breaking tile template placeholders.
    if (base.includes('?')) {
        return `${base}&${qs}`;
    }
    return `${base}?${qs}`;
}

function prefersMvt(layerConfig) {
    return Boolean(
        layerConfig.mvtUrl ||
            layerConfig.mvt_url ||
            layerConfig.type === 'mvt' ||
            layerConfig.type === 'vector-tile'
    );
}

const createOverlayLayer = (layerConfig) => {
    let layer = null;
    const styleFn = createStyleFunction(layerConfig);
    const heatmap = isHeatmapRenderer(layerConfig);

    if (prefersMvt(layerConfig)) {
        const tileUrl = buildMvtUrl(layerConfig);
        const source = new VectorTileSource({
            format: new MVT(),
            url: tileUrl,
        });

        if (heatmap) {
            // Heatmap needs a Vector source of points — fall through to GeoJSON if available.
            if (layerConfig.url && (layerConfig.type === 'vector' || layerConfig.type === 'wfs' || layerConfig.geojsonUrl)) {
                const options = getHeatmapOptions(layerConfig);
                layer = new HeatmapLayer({
                    source: new VectorSource({
                        url: layerConfig.geojsonUrl || layerConfig.url,
                        format: new GeoJSON(),
                    }),
                    ...options,
                    opacity: layerConfig.opacity ?? 1,
                });
            } else {
                // Vector tiles with simple style as approximation when no GeoJSON.
                layer = new VectorTileLayer({
                    source,
                    style: styleFn || undefined,
                    opacity: layerConfig.opacity ?? 1,
                });
            }
        } else {
            layer = new VectorTileLayer({
                source,
                style: styleFn || undefined,
                opacity: layerConfig.opacity ?? 1,
            });
        }
    } else if (layerConfig.type === 'wms') {
        layer = new TileLayer({
            source: attachTileCache(
                new TileWMS({
                    url: layerConfig.url,
                    params: {
                        LAYERS: layerConfig.layers,
                        TILED: true,
                        ...(layerConfig.wmsParams || {}),
                    },
                    serverType: 'geoserver',
                }),
                'wms',
            ),
            opacity: layerConfig.opacity || 1,
        });
    } else if (layerConfig.type === 'vector' || layerConfig.type === 'wfs' || layerConfig.type === 'geojson') {
        const source = new VectorSource({
            url: layerConfig.url,
            format: new GeoJSON(),
        });

        if (heatmap) {
            const options = getHeatmapOptions(layerConfig);
            layer = new HeatmapLayer({
                source,
                ...options,
                opacity: layerConfig.opacity ?? 1,
            });
        } else {
            layer = new VectorLayer({
                source,
                style: styleFn || undefined,
                opacity: layerConfig.opacity || 1,
            });
        }
    }

    if (layer) {
        layer.set('id', layerConfig.id);
        layer.set('gisLayerId', layerConfig.id);
    }

    return layer;
};

function extractGeoJSON(payload) {
    if (!payload) {
        return null;
    }
    if (payload.type === 'FeatureCollection' || payload.type === 'Feature') {
        return payload;
    }
    if (payload.geojson) {
        return payload.geojson;
    }
    if (payload.result?.geojson) {
        return payload.result.geojson;
    }
    if (payload.result?.type === 'FeatureCollection' || payload.result?.type === 'Feature') {
        return payload.result;
    }
    if (Array.isArray(payload.features)) {
        return { type: 'FeatureCollection', features: payload.features };
    }
    if (payload.result?.features) {
        return { type: 'FeatureCollection', features: payload.result.features };
    }
    return null;
}

export default function MapView() {
    const containerRef = useRef(null);
    const mapRef = useRef(null);
    const popupRef = useRef(null);
    const popupOverlayRef = useRef(null);
    const analysisSourceRef = useRef(null);
    const analysisLayerRef = useRef(null);

    const setMap = useMapStore((state) => state.setMap);
    const updateViewport = useMapStore((state) => state.updateViewport);
    const setAnalysisResults = useMapStore((state) => state.setAnalysisResults);
    const basemap = useMapStore((state) => state.basemap);
    const layers = useMapStore((state) => state.layers);
    const analysisResults = useMapStore((state) => state.analysisResults);

    useEffect(() => {
        if (!containerRef.current || mapRef.current) {
            return undefined;
        }

        const popup = document.createElement('div');
        popup.className = 'ol-popup';
        popup.style.cssText = `
            background: white;
            padding: 15px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            min-width: 200px;
        `;
        popupRef.current = popup;

        const popupOverlay = new Overlay({
            element: popup,
            autoPan: true,
            autoPanAnimation: {
                duration: 250,
            },
        });
        popupOverlayRef.current = popupOverlay;

        const analysisSource = new VectorSource();
        analysisSourceRef.current = analysisSource;

        const analysisLayer = new VectorLayer({
            source: analysisSource,
            style: ANALYSIS_STYLE,
            zIndex: 9999,
        });
        analysisLayer.set('id', ANALYSIS_LAYER_ID);
        analysisLayerRef.current = analysisLayer;

        const initialViewport = useMapStore.getState().viewport;
        const initialBasemap = useMapStore.getState().basemap;

        const map = new Map({
            target: containerRef.current,
            layers: [createBaseLayer(initialBasemap), analysisLayer],
            overlays: [popupOverlay],
            view: new View({
                center: fromLonLat(initialViewport.center || [0, 0]),
                zoom: initialViewport.zoom ?? 2,
                rotation: initialViewport.rotation ?? 0,
            }),
            controls: defaultControls().extend([
                new FullScreen(),
                new ScaleLine(),
                new ZoomSlider(),
            ]),
        });

        const selectInteraction = new Select({
            condition: click,
            filter: (feature, layer) => layer !== analysisLayer,
        });
        map.addInteraction(selectInteraction);

        selectInteraction.on('select', () => {
            popupOverlay.setPosition(undefined);
        });

        map.getView().on('change', () => {
            const view = map.getView();
            const center = view.getCenter();
            updateViewport({
                center: center ? toLonLat(center) : [0, 0],
                zoom: view.getZoom(),
                rotation: view.getRotation(),
            });
        });

        const onAnalysisResult = (event) => {
            const geojson = extractGeoJSON(event.detail);
            setAnalysisResults(event.detail ?? null);
            if (geojson) {
                // Store already updated; local effect will redraw.
            }
        };

        const onGeocodeFly = (event) => {
            const { lon, lat, zoom } = event.detail || {};
            if (lon == null || lat == null) {
                return;
            }
            map.getView().animate({
                center: fromLonLat([Number(lon), Number(lat)]),
                zoom: zoom ?? 14,
                duration: 500,
            });
        };

        window.addEventListener('gis:analysis-result', onAnalysisResult);
        window.addEventListener('gis:geocode-fly', onGeocodeFly);

        mapRef.current = map;
        setMap(map);

        return () => {
            window.removeEventListener('gis:analysis-result', onAnalysisResult);
            window.removeEventListener('gis:geocode-fly', onGeocodeFly);
            setMap(null);
            map.setTarget(null);
            map.dispose();
            mapRef.current = null;
            popupRef.current = null;
            popupOverlayRef.current = null;
            analysisSourceRef.current = null;
            analysisLayerRef.current = null;
        };
        // Initialize once on mount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const map = mapRef.current;
        if (!map) {
            return;
        }

        map.getLayers().setAt(0, createBaseLayer(basemap));
    }, [basemap]);

    useEffect(() => {
        const map = mapRef.current;
        const analysisLayer = analysisLayerRef.current;
        if (!map) {
            return;
        }

        const olLayers = map.getLayers();
        const basemapLayer = olLayers.item(0);
        const toRemove = [];

        olLayers.forEach((layer) => {
            if (layer === basemapLayer || layer === analysisLayer) {
                return;
            }
            toRemove.push(layer);
        });
        toRemove.forEach((layer) => map.removeLayer(layer));

        layers.forEach((layerConfig) => {
            if (layerConfig.visible === false) {
                return;
            }

            const layer = createOverlayLayer(layerConfig);
            if (!layer) {
                return;
            }

            // Keep analysis overlay on top: insert just before it when present.
            const analysisIndex = analysisLayer
                ? olLayers.getArray().indexOf(analysisLayer)
                : -1;
            if (analysisIndex >= 0) {
                olLayers.insertAt(analysisIndex, layer);
            } else {
                map.addLayer(layer);
            }
        });

        if (analysisLayer) {
            if (olLayers.getArray().indexOf(analysisLayer) === -1) {
                map.addLayer(analysisLayer);
            }
            analysisLayer.setZIndex(9999);
        }
    }, [layers]);

    // Draw analysis GeoJSON onto the dedicated overlay.
    useEffect(() => {
        const source = analysisSourceRef.current;
        if (!source) {
            return;
        }

        source.clear();

        const geojson = extractGeoJSON(analysisResults);
        if (!geojson) {
            return;
        }

        try {
            const features = new GeoJSON().readFeatures(geojson, {
                featureProjection: 'EPSG:3857',
                dataProjection: 'EPSG:4326',
            });
            source.addFeatures(features);

            const map = mapRef.current;
            if (map && features.length) {
                const extent = source.getExtent();
                if (extent && extent.every((v) => Number.isFinite(v))) {
                    map.getView().fit(extent, {
                        duration: 400,
                        padding: [40, 40, 40, 40],
                        maxZoom: 16,
                    });
                }
            }
        } catch (error) {
            console.warn('Failed to render analysis GeoJSON:', error);
        }
    }, [analysisResults]);

    return <div ref={containerRef} className="ol-map" />;
}

// Re-export helpers useful for MapWorkspace wiring / tests.
export { ANALYSIS_LAYER_ID, normalizeStyleConfig, extractGeoJSON };
