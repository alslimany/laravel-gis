import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import Draw from 'ol/interaction/Draw';
import Modify from 'ol/interaction/Modify';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import { Style, Stroke, Fill, Circle as CircleStyle } from 'ol/style';
import WKT from 'ol/format/WKT';
import { toLonLat } from 'ol/proj';
import { useMapStore } from './store/mapStore';
import MapView, { ANALYSIS_LAYER_ID } from './map/MapView';
import LayerPanel from './panels/LayerPanel';
import ToolPanel from './panels/ToolPanel';
import StyleEditor from './panels/StyleEditor';
import AnalysisPanel from './panels/AnalysisPanel';
import Legend from './panels/Legend';
import GeocodeSearch from './components/GeocodeSearch';
import TemporalSlider from './components/TemporalSlider';
import FeatureDock from './components/FeatureDock';
import GisAssistantPanel from './components/GisAssistantPanel';
import { jsonHeaders } from './utils/csrf';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { IconButton } from './components/IconButton';
import { useTranslation } from '@/lib/i18n';
import { ArrowLeft, Bot, ChartColumn, CloudRain, Download, Info, Layers, List, Palette, Pencil, Printer, Save, Share2, X } from 'lucide-react';

function basemapLabel(id, t) {
    if (id === 'osm') {
        return t('maps.basemap_street');
    }
    if (id === 'imagery') {
        return t('maps.basemap_satellite');
    }

    return id;
}

const EarlyWarningPanel = lazy(() => import('@early-warning/EarlyWarningPanel'));

const SKIP_DETAIL_KEYS = new Set(['geometry', 'geom', 'the_geom', 'wkb_geometry', 'geojson', 'properties']);
const DRAW_LAYER_ID = '__draw__';
const HIGHLIGHT_LAYER_ID = '__highlight__';

/**
 * Normalize API / spatial-query / GeoJSON payloads into a GeoJSON Feature.
 * Spatial query returns DB rows with a `geojson` string, not Feature objects.
 */
function toGeoJsonFeature(raw) {
    if (!raw || typeof raw !== 'object') {
        return null;
    }

    if (raw.type === 'Feature' && raw.geometry) {
        return raw;
    }

    let geometry = raw.geometry && typeof raw.geometry === 'object' ? raw.geometry : null;
    if (!geometry && raw.geojson) {
        try {
            const parsed = typeof raw.geojson === 'string' ? JSON.parse(raw.geojson) : raw.geojson;
            if (parsed?.type === 'Feature') {
                geometry = parsed.geometry;
            } else if (parsed?.type) {
                geometry = parsed;
            }
        } catch {
            geometry = null;
        }
    }

    if (!geometry) {
        return null;
    }

    const properties =
        raw.properties && typeof raw.properties === 'object'
            ? { ...raw.properties }
            : Object.fromEntries(
                  Object.entries(raw).filter(([key]) => !SKIP_DETAIL_KEYS.has(String(key).toLowerCase())),
              );

    return {
        type: 'Feature',
        id: raw.id ?? properties.id ?? null,
        geometry,
        properties,
    };
}

function featureAttributes(feature) {
    if (!feature || typeof feature !== 'object') {
        return { properties: {}, geojson: null };
    }

    const normalized = toGeoJsonFeature(feature);
    const source = normalized?.properties
        || (feature.properties && typeof feature.properties === 'object' ? feature.properties : feature);
    const geojson = normalized?.geometry
        || feature.geojson
        || (typeof source === 'object' ? source.geojson : null)
        || null;
    const properties = {};

    Object.entries(source || {}).forEach(([key, value]) => {
        if (SKIP_DETAIL_KEYS.has(key.toLowerCase()) || value == null || value === '') {
            return;
        }
        if (typeof value === 'object') {
            return;
        }
        const text = String(value);
        if (text.length > 240) {
            return;
        }
        properties[key] = text;
    });

    return { properties, geojson };
}

function clickBufferWkt(lon, lat, bufferMeters) {
    const latRad = (lat * Math.PI) / 180;
    const metersPerDegLat = 111_320;
    const metersPerDegLon = Math.max(metersPerDegLat * Math.cos(latRad), 1_000);
    const dLat = bufferMeters / metersPerDegLat;
    const dLon = bufferMeters / metersPerDegLon;

    return `POLYGON((${lon - dLon} ${lat - dLat}, ${lon + dLon} ${lat - dLat}, ${lon + dLon} ${lat + dLat}, ${lon - dLon} ${lat + dLat}, ${lon - dLon} ${lat - dLat}))`;
}

function resolveStoreLayer(storeLayers, layerId) {
    if (layerId == null) {
        return null;
    }

    return (
        storeLayers.find((layer) => layer.id === layerId)
        || storeLayers.find((layer) => String(layer.id) === String(layerId))
        || null
    );
}

const emptyProps = () => ({
    name: '',
    description: '',
    type: '',
    notes: '',
});

export default function MapWorkspace({ mode = 'edit' }) {
    const { t } = useTranslation();
    const enabledPlugins = useMapStore((state) => state.plugins);
    const earlyWarningEnabled = enabledPlugins.includes('early-warning');

    const viewing = mode === 'view';
    const layerMode = mode === 'layer';
    const backHref = layerMode ? '/layers' : '/maps';
    const backLabel = layerMode ? t('layers.title') : t('maps.title');
    const map = useMapStore((state) => state.map);
    const layers = useMapStore((state) => state.layers);
    const selectedLayerId = useMapStore((state) => state.selectedLayer);
    const viewport = useMapStore((state) => state.viewport);
    const basemap = useMapStore((state) => state.basemap);
    const availableBasemaps = useMapStore((state) => state.availableBasemaps);
    const setBasemap = useMapStore((state) => state.setBasemap);
    const mapName = useMapStore((state) => state.mapName);
    const mapId = useMapStore((state) => state.mapId);
    const getLayerById = useMapStore((state) => state.getLayerById);
    const addLayer = useMapStore((state) => state.addLayer);
    const setMeasureResult = useMapStore((state) => state.setMeasureResult);
    const setIdentifyResult = useMapStore((state) => state.setIdentifyResult);
    const setAnalysisResults = useMapStore((state) => state.setAnalysisResults);
    const measureResult = useMapStore((state) => state.measureResult);
    const identifyResult = useMapStore((state) => state.identifyResult);

    const selectedLayer = getLayerById(selectedLayerId) || null;

    const [panel, setPanel] = useState(mode === 'view' ? 'layers' : null);
    const [leftTab, setLeftTab] = useState('layers');
    const [cursor, setCursor] = useState(null);
    const [scaleText, setScaleText] = useState('');
    const [dockMode, setDockMode] = useState(null); // create | edit | identify
    const [editingFeatureId, setEditingFeatureId] = useState(null);
    const [drawnGeometryWkt, setDrawnGeometryWkt] = useState(null);
    const [featureProperties, setFeatureProperties] = useState(emptyProps());
    const [featureDetails, setFeatureDetails] = useState(null);

    const currentDrawRef = useRef(null);
    const modifyRef = useRef(null);
    const drawLayerRef = useRef(null);
    const currentFeatureRef = useRef(null);
    const clickHandlerRef = useRef(null);

    const ensureDrawLayer = () => {
        if (!map) {
            return null;
        }
        if (!drawLayerRef.current) {
            const source = new VectorSource();
            drawLayerRef.current = new VectorLayer({
                source,
                style: new Style({
                    fill: new Fill({ color: 'rgba(15, 118, 110, 0.2)' }),
                    stroke: new Stroke({ color: '#0f766e', width: 2 }),
                    image: new CircleStyle({
                        radius: 7,
                        fill: new Fill({ color: '#0f766e' }),
                    }),
                }),
                zIndex: 999,
            });
            drawLayerRef.current.set('id', DRAW_LAYER_ID);
            drawLayerRef.current.set('gisLayerId', DRAW_LAYER_ID);
            map.addLayer(drawLayerRef.current);
        }
        return drawLayerRef.current;
    };

    const clearInteractions = () => {
        if (currentDrawRef.current && map) {
            map.removeInteraction(currentDrawRef.current);
            currentDrawRef.current = null;
        }
        if (modifyRef.current && map) {
            map.removeInteraction(modifyRef.current);
            modifyRef.current = null;
        }
        if (clickHandlerRef.current && map) {
            map.un('singleclick', clickHandlerRef.current);
            clickHandlerRef.current = null;
        }
    };

    useEffect(() => {
        return () => {
            clearInteractions();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map]);

    useEffect(() => {
        if (dockMode || identifyResult) {
            setPanel('properties');
        }
    }, [dockMode, identifyResult]);

    useEffect(() => {
        if (!map) {
            return undefined;
        }

        const updateScale = () => {
            const view = map.getView();
            const resolution = view.getResolution();
            const metersPerUnit = view.getProjection().getMetersPerUnit();
            if (!resolution || !metersPerUnit) {
                return;
            }
            const scale = Math.round((resolution * metersPerUnit) / 0.00028);
            setScaleText(`1:${scale.toLocaleString()}`);
        };

        const onPointer = (event) => {
            const [lon, lat] = toLonLat(event.coordinate);
            setCursor({
                lon: lon.toFixed(5),
                lat: lat.toFixed(5),
            });
        };

        map.on('pointermove', onPointer);
        map.getView().on('change:resolution', updateScale);
        updateScale();

        return () => {
            map.un('pointermove', onPointer);
            map.getView().un('change:resolution', updateScale);
        };
    }, [map]);

    const queryFeatureRef = useRef(null);

    useEffect(() => {
        if (!map) {
            return undefined;
        }

        const handler = async (event) => {
            const tool = useMapStore.getState().activeTool;
            const blocked = tool && !['pan', 'zoom-extent', 'zoom-in', 'zoom-out'].includes(tool);
            if (blocked || !queryFeatureRef.current) {
                return;
            }

            const hit = await queryFeatureRef.current(event);
            if (!hit.feature) {
                setFeatureDetails(null);
                return;
            }

            const attributes = featureAttributes(hit.feature);
            setFeatureDetails({
                layerName: hit.layer?.name || t('maps.feature'),
                properties: attributes.properties,
                geojson: attributes.geojson,
            });
        };

        map.on('singleclick', handler);
        return () => map.un('singleclick', handler);
    }, [map]);

    const highlightSourceRef = useRef(null);

    useEffect(() => {
        if (!map) {
            return undefined;
        }

        const source = new VectorSource();
        const layer = new VectorLayer({
            source,
            style: new Style({
                stroke: new Stroke({ color: '#334155', width: 2 }),
                fill: new Fill({ color: 'rgba(71, 85, 105, 0.45)' }),
            }),
            zIndex: 40,
        });
        layer.set('id', HIGHLIGHT_LAYER_ID);
        layer.set('gisLayerId', HIGHLIGHT_LAYER_ID);
        map.addLayer(layer);
        highlightSourceRef.current = source;

        return () => {
            map.removeLayer(layer);
            highlightSourceRef.current = null;
        };
    }, [map]);

    useEffect(() => {
        const source = highlightSourceRef.current;
        if (!source) {
            return;
        }

        source.clear();
        if (!featureDetails?.geojson) {
            return;
        }

        try {
            const geojson = typeof featureDetails.geojson === 'string'
                ? JSON.parse(featureDetails.geojson)
                : featureDetails.geojson;
            const features = new GeoJSON().readFeatures(geojson, {
                dataProjection: 'EPSG:4326',
                featureProjection: 'EPSG:3857',
            });
            source.addFeatures(features);
        } catch (error) {
            console.warn('Failed to highlight feature:', error);
        }
    }, [featureDetails, map]);

    const openPanel = (id) => {
        setPanel((current) => (current === id ? null : id));
    };

    const closeRightPanel = () => {
        setPanel(null);
    };

    const syncWktFromFeature = (feature) => {
        try {
            const geom4326 = feature.getGeometry().clone().transform('EPSG:3857', 'EPSG:4326');
            return new WKT().writeGeometry(geom4326);
        } catch (error) {
            console.error('Failed to convert geometry to WKT:', error);
            return null;
        }
    };

    const loadFeatureOntoMap = (featurePayload) => {
        const layer = ensureDrawLayer();
        const feature = toGeoJsonFeature(featurePayload);
        if (!layer || !feature?.geometry) {
            return null;
        }
        try {
            const source = layer.getSource();
            source.clear();
            const olFeature = new GeoJSON().readFeature(feature, {
                dataProjection: 'EPSG:4326',
                featureProjection: 'EPSG:3857',
            });
            source.addFeature(olFeature);
            currentFeatureRef.current = olFeature;
            return olFeature;
        } catch (error) {
            console.error('Failed to load feature onto map:', error);
            return null;
        }
    };

    const enableModify = () => {
        const layer = ensureDrawLayer();
        if (!map || !layer) {
            return;
        }
        if (modifyRef.current) {
            map.removeInteraction(modifyRef.current);
        }
        const modify = new Modify({ source: layer.getSource() });
        modify.on('modifyend', () => {
            if (currentFeatureRef.current) {
                setDrawnGeometryWkt(syncWktFromFeature(currentFeatureRef.current));
            }
        });
        modifyRef.current = modify;
        map.addInteraction(modify);
    };

    const fetchFeatureById = async (layer, featureId) => {
        if (!layer?.id || featureId == null || featureId === '') {
            return null;
        }

        try {
            const response = await fetch(`/api/layers/${layer.id}/features/${featureId}`, {
                headers: jsonHeaders(),
            });
            const data = await response.json();
            if (data.success && data.feature) {
                return toGeoJsonFeature(data.feature);
            }
        } catch (error) {
            console.error('Feature fetch failed:', error);
        }

        return null;
    };

    const queryFeatureAtClick = async (event) => {
        const [lon, lat] = toLonLat(event.coordinate);
        const storeLayers = useMapStore.getState().layers || [];
        const selectedId = useMapStore.getState().selectedLayer;
        const skipLayerIds = new Set([DRAW_LAYER_ID, HIGHLIGHT_LAYER_ID, ANALYSIS_LAYER_ID]);

        // Prefer on-map MVT/vector hits (works for thin lines/points with hitTolerance).
        if (map && event.pixel) {
            let pixelHit = null;
            map.forEachFeatureAtPixel(
                event.pixel,
                (olFeature, olLayer) => {
                    const layerId = olLayer?.get?.('gisLayerId') ?? olLayer?.get?.('id');
                    if (layerId == null || skipLayerIds.has(layerId)) {
                        return undefined;
                    }
                    const featureId =
                        olFeature.get?.('id')
                        ?? olFeature.getId?.()
                        ?? olFeature.getProperties?.()?.id;
                    if (featureId == null || featureId === '') {
                        return undefined;
                    }
                    pixelHit = { layerId, featureId };
                    return true;
                },
                {
                    hitTolerance: 10,
                    layerFilter: (olLayer) => {
                        const layerId = olLayer?.get?.('gisLayerId') ?? olLayer?.get?.('id');
                        return layerId != null && !skipLayerIds.has(layerId);
                    },
                },
            );

            if (pixelHit) {
                const layer = resolveStoreLayer(storeLayers, pixelHit.layerId);
                if (layer) {
                    const feature = await fetchFeatureById(layer, pixelHit.featureId);
                    if (feature) {
                        return { lon, lat, feature, layer };
                    }
                }
            }
        }

        const resolution = map?.getView()?.getResolution() || 1;
        const metersPerUnit = map?.getView()?.getProjection()?.getMetersPerUnit() || 1;
        const bufferMeters = Math.max(20, resolution * metersPerUnit * 10);
        const wkt = clickBufferWkt(lon, lat, bufferMeters);

        const candidates = [
            ...storeLayers.filter((layer) => layer.id === selectedId && layer.visible !== false),
            ...storeLayers.filter(
                (layer) => layer.id !== selectedId && layer.visible !== false && layer.id != null,
            ),
        ].filter((layer) => layer.type !== 'wms' && Number.isFinite(Number(layer.id)));

        for (const layer of candidates) {
            try {
                const response = await fetch('/api/analysis/spatial-query', {
                    method: 'POST',
                    headers: jsonHeaders(),
                    body: JSON.stringify({
                        layer_id: layer.id,
                        operation: 'intersects',
                        wkt,
                    }),
                });
                const data = await response.json();
                if (data.success && data.features?.length) {
                    const feature =
                        toGeoJsonFeature(data.features[0])
                        || (await fetchFeatureById(layer, data.features[0]?.id));
                    if (feature) {
                        return { lon, lat, feature, layer };
                    }
                }
            } catch (error) {
                console.error('Spatial query failed:', error);
            }
        }

        return { lon, lat, feature: null, layer: null };
    };

    queryFeatureRef.current = queryFeatureAtClick;

    const initializeDrawing = (toolId) => {
        if (!map) {
            return;
        }

        ensureDrawLayer();

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

        const draw = new Draw({
            source: drawLayerRef.current.getSource(),
            type: geometryType,
        });

        draw.on('drawend', (event) => {
            currentFeatureRef.current = event.feature;
            const wkt = syncWktFromFeature(event.feature);
            setDrawnGeometryWkt(wkt);
            setEditingFeatureId(null);
            setFeatureProperties(emptyProps());
            setDockMode('create');
        });

        currentDrawRef.current = draw;
        map.addInteraction(draw);
    };

    const initializeMeasure = (toolId) => {
        if (!map) {
            return;
        }

        ensureDrawLayer();
        const isArea = toolId === 'measure-area';

        const draw = new Draw({
            source: drawLayerRef.current.getSource(),
            type: isArea ? 'Polygon' : 'LineString',
            maxPoints: isArea ? undefined : 2,
        });

        draw.on('drawend', async (event) => {
            const geometry = event.feature.getGeometry();
            try {
                if (isArea) {
                    const wkt = new WKT().writeGeometry(
                        geometry.clone().transform('EPSG:3857', 'EPSG:4326')
                    );
                    const response = await fetch('/api/analysis/measure-area', {
                        method: 'POST',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ wkt }),
                    });
                    const data = await response.json();
                    if (data.success) {
                        setMeasureResult({ type: 'area', ...data });
                    }
                } else {
                    const coords = geometry.getCoordinates().map((c) => toLonLat(c));
                    if (coords.length < 2) {
                        return;
                    }
                    const [lon1, lat1] = coords[0];
                    const [lon2, lat2] = coords[coords.length - 1];
                    const response = await fetch('/api/analysis/measure-distance', {
                        method: 'POST',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ lat1, lon1, lat2, lon2 }),
                    });
                    const data = await response.json();
                    if (data.success) {
                        setMeasureResult({ type: 'distance', ...data });
                    }
                }
            } catch (error) {
                console.error('Measure failed:', error);
                setMeasureResult({ type: 'error', message: error.message });
            }
        });

        currentDrawRef.current = draw;
        map.addInteraction(draw);
    };

    const initializeIdentify = () => {
        if (!map) {
            return;
        }

        const handler = async (event) => {
            const hit = await queryFeatureAtClick(event);
            const feature = toGeoJsonFeature(hit.feature);

            if (hit.layer?.id != null) {
                useMapStore.getState().selectLayer(hit.layer.id);
            }

            setIdentifyResult({
                lon: Number(hit.lon.toFixed(6)),
                lat: Number(hit.lat.toFixed(6)),
                layerId: hit.layer?.id ?? selectedLayer?.id ?? null,
                feature,
            });

            if (feature) {
                const id = feature.id ?? feature.properties?.id;
                const attributes = featureAttributes(feature);
                setEditingFeatureId(id ?? null);
                setFeatureProperties(attributes.properties);
                setDrawnGeometryWkt(null);
                setDockMode('identify');
                setFeatureDetails({
                    layerName: hit.layer?.name || selectedLayer?.name || t('maps.feature'),
                    properties: attributes.properties,
                    geojson: attributes.geojson,
                });
            } else {
                setFeatureDetails(null);
            }
        };

        clickHandlerRef.current = handler;
        map.on('singleclick', handler);
    };

    const initializeSelect = () => {
        if (!map) {
            return;
        }

        const handler = async (event) => {
            const hit = await queryFeatureAtClick(event);
            const feature = toGeoJsonFeature(hit.feature);
            if (!feature) {
                return;
            }

            if (hit.layer?.id != null) {
                useMapStore.getState().selectLayer(hit.layer.id);
            }

            const id = feature.id ?? feature.properties?.id;
            const hitLayer = hit.layer?.id != null ? getLayerById(hit.layer.id) : null;
            if (hit.layer?.read_only || hitLayer?.read_only) {
                // Read-only (SQL-view) layer: show the feature, never open the editor.
                setEditingFeatureId(id ?? null);
                setFeatureProperties(featureAttributes(feature).properties);
                setDrawnGeometryWkt(null);
                setDockMode('identify');
                return;
            }
            const olFeature = loadFeatureOntoMap(feature);
            if (olFeature) {
                setDrawnGeometryWkt(syncWktFromFeature(olFeature));
                enableModify();
            }
            setEditingFeatureId(id ?? null);
            setFeatureProperties(
                feature.properties && Object.keys(feature.properties).length > 0
                    ? feature.properties
                    : emptyProps(),
            );
            setDockMode('edit');
        };

        clickHandlerRef.current = handler;
        map.on('singleclick', handler);
    };

    const handleToolSelection = (toolId) => {
        clearInteractions();
        setMeasureResult(null);
        setIdentifyResult(null);

        if (!toolId || !map) {
            return;
        }

        if (toolId.startsWith('draw-')) {
            initializeDrawing(toolId);
            return;
        }

        if (toolId === 'measure-distance' || toolId === 'measure-area') {
            initializeMeasure(toolId);
            return;
        }

        if (toolId === 'identify') {
            initializeIdentify();
            return;
        }

        if (toolId === 'select') {
            initializeSelect();
        }
    };

    const handleToolSelectionRef = useRef(handleToolSelection);
    handleToolSelectionRef.current = handleToolSelection;

    // Layer builder: tools only, Select armed so features can be edited immediately.
    useEffect(() => {
        if (!map || !layerMode) {
            return undefined;
        }

        useMapStore.getState().setActiveTool('select');
        handleToolSelectionRef.current('select');
        setLeftTab('tools');

        return undefined;
    }, [map, layerMode]);

    const closeDock = () => {
        setDockMode(null);
        setEditingFeatureId(null);
        setDrawnGeometryWkt(null);
        setFeatureProperties(emptyProps());
        currentFeatureRef.current = null;
        if (modifyRef.current && map) {
            map.removeInteraction(modifyRef.current);
            modifyRef.current = null;
        }
        setPanel((current) => (current === 'properties' ? null : current));
    };

    const handleDockSaved = (savedFeature) => {
        const layerId = selectedLayer?.id;
        const wktSnapshot = drawnGeometryWkt;
        const geojsonGeometry = savedFeature?.geometry ?? null;

        if (layerId) {
            useMapStore.getState().refreshLayerTiles(layerId);
        }

        closeDock();
        if (drawLayerRef.current) {
            drawLayerRef.current.getSource().clear();
        }

        // Zoom to the feature that was just saved so it does not look like it vanished.
        if (!map) {
            return;
        }

        try {
            let extent = null;
            if (geojsonGeometry) {
                extent = new GeoJSON()
                    .readGeometry(geojsonGeometry, {
                        dataProjection: 'EPSG:4326',
                        featureProjection: 'EPSG:3857',
                    })
                    .getExtent();
            } else if (wktSnapshot) {
                extent = new WKT()
                    .readGeometry(wktSnapshot, {
                        dataProjection: 'EPSG:4326',
                        featureProjection: 'EPSG:3857',
                    })
                    .getExtent();
            }

            if (extent && extent.every((value) => Number.isFinite(value))) {
                map.getView().fit(extent, {
                    duration: 400,
                    padding: [60, 60, 60, 60],
                    maxZoom: 14,
                });
            }
        } catch (error) {
            console.warn('Could not zoom to saved feature', error);
        }
    };

    const handleAnalysisComplete = (result) => {
        setAnalysisResults(result);
        if (result?.result_layer) {
            const layer = result.result_layer;
            addLayer({
                id: layer.id,
                name: layer.name,
                type: 'mvt',
                visible: true,
                mvtUrl: `/api/layers/${layer.id}/tiles/{z}/{x}/{y}.mvt`,
                style_config: layer.style_config || { renderer: 'simple' },
            });
        }
        window.dispatchEvent(
            new CustomEvent('gis:analysis-result', {
                detail: { geojson: result?.buffered_geojson || result?.geojson || result },
            })
        );
    };

    const captureMapImage = () =>
        new Promise((resolve, reject) => {
            if (!map) {
                reject(new Error('Map not ready'));
                return;
            }
            map.once('rendercomplete', () => {
                try {
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
                                    .match(/^matrix\(([^()]*)\)$/)?.[1]
                                    ?.split(',')
                                    .map(Number);
                                if (matrix) {
                                    CanvasRenderingContext2D.prototype.setTransform.apply(
                                        mapContext,
                                        matrix
                                    );
                                }
                                mapContext.drawImage(canvas, 0, 0);
                            }
                        }
                    );
                    const resolution = map.getView().getResolution();
                    const metersPerUnit = map
                        .getView()
                        .getProjection()
                        .getMetersPerUnit();
                    const scaleDenominator = Math.round(
                        (resolution * metersPerUnit) / 0.00028
                    );
                    const legend = layers.flatMap((layer) => {
                        const entries = layer.style_config?.legend;
                        if (Array.isArray(entries) && entries.length) {
                            return entries.map((entry) => ({
                                label: entry.label || layer.name,
                                color: entry.color || entry.fill || '#0f766e',
                            }));
                        }
                        return [
                            {
                                label: layer.name,
                                color:
                                    layer.style_config?.symbol?.fill_color ||
                                    layer.style?.fillColor ||
                                    '#0f766e',
                            },
                        ];
                    });
                    resolve({
                        image: mapCanvas.toDataURL('image/png'),
                        scaleDenominator,
                        legend,
                        title: mapName || 'Map',
                    });
                } catch (error) {
                    reject(error);
                }
            });
            map.renderSync();
        });

    const printMap = async () => {
        if (!mapId) {
            alert(t('maps.save_before_print'));
            return;
        }
        try {
            const payload = await captureMapImage();
            const response = await fetch(`/maps/${mapId}/print/pdf`, {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify(payload),
            });
            if (!response.ok) {
                throw new Error(t('maps.print_failed'));
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `${(mapName || 'map').replace(/\s+/g, '-')}-print.pdf`;
            anchor.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            console.error(error);
            alert(error.message || t('maps.print_failed'));
        }
    };

    const saveMap = async () => {
        try {
            const name = prompt(t('maps.enter_map_name'), mapName || t('maps.my_map'));
            if (!name) {
                return;
            }

            const mapData = {
                name,
                description: '',
                viewport,
                basemap,
                layers,
                is_public: false,
            };

            const response = mapId
                ? await window.axios.put(`/api/maps/${mapId}`, mapData)
                : await window.axios.post('/api/maps', mapData);

            alert(t('maps.saved_redirect'));

            const savedId = response.data?.map?.id || mapId;
            if (savedId) {
                window.location.href = `/maps/${savedId}`;
            }
        } catch (error) {
            console.error('Error saving map:', error);
            if (error.response) {
                alert(
                    t('maps.save_failed', {
                        message: error.response.data.message || error.response.statusText,
                    }),
                );
            } else if (error.request) {
                alert(t('maps.save_failed_no_response'));
            } else {
                alert(t('maps.save_failed', { message: error.message }));
            }
        }
    };

    const exportMap = () => {
        const mapData = { viewport, basemap, layers };
        const blob = new Blob([JSON.stringify(mapData, null, 2)], {
            type: 'application/json',
        });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'map-config.json';
        anchor.click();
        URL.revokeObjectURL(url);
    };

    const drawerTitle = dockMode === 'create'
        ? t('maps.new_feature')
        : dockMode === 'edit'
          ? t('maps.edit_feature')
          : dockMode === 'identify'
            ? t('maps.identified_feature')
            : t('maps.attributes');

    const statusLabel = measureResult?.type
        ? t('common.measure')
        : dockMode
          ? drawerTitle
          : t('common.ready');

    return (
        <div className="map-builder map-stage">
            <div className="map-viewport">
                <MapView />
                <TemporalSlider />
                {featureDetails && (
                    <Card className="map-feature-details gap-0 py-0 shadow-lg">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground">{t('common.details').toUpperCase()}</p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={() => setFeatureDetails(null)}
                            >
                                <X className="size-4" />
                                <span className="sr-only">{t('common.close_details')}</span>
                            </Button>
                        </div>
                        <div className="flex items-center gap-2 border-b px-4 py-3 text-sm font-medium">
                            <Layers className="size-4 text-muted-foreground" />
                            {featureDetails.layerName}
                        </div>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="px-4">{t('common.field_name')}</TableHead>
                                        <TableHead className="px-4">{t('common.value')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {Object.entries(featureDetails.properties).length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={2} className="px-4 text-muted-foreground">
                                                {t('maps.no_attributes')}
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        Object.entries(featureDetails.properties).map(([name, value]) => (
                                            <TableRow key={name}>
                                                <TableCell className="px-4 text-muted-foreground">{name}</TableCell>
                                                <TableCell className="px-4 font-medium">{value}</TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Card className="map-topbar gap-0 py-0 shadow-sm">
                <CardContent className="map-topbar-inner p-2 px-2.5">
                    <div className="map-topbar-start">
                        <Button asChild variant="ghost" size="sm" className="h-9 gap-1.5 px-2 text-foreground hover:text-accent-foreground no-underline hover:no-underline">
                            <a href={backHref}>
                                <ArrowLeft className="size-4 rtl:rotate-180" />
                                {backLabel}
                            </a>
                        </Button>
                        <Separator orientation="vertical" className="hidden h-5 sm:block" />
                        <p className="map-title truncate text-sm font-semibold tracking-tight">
                            {mapName || (layerMode ? t('layers.builder') : t('maps.map'))}
                        </p>
                    </div>
                    <div className="map-topbar-center">
                        <GeocodeSearch />
                        <Select value={basemap} onValueChange={setBasemap}>
                            <SelectTrigger
                                size="sm"
                                className="map-basemap h-9 w-[11.5rem] shrink-0 bg-background shadow-xs"
                                aria-label={t('maps.basemap')}
                            >
                                <SelectValue placeholder={t('maps.basemap')} />
                            </SelectTrigger>
                            <SelectContent>
                                {availableBasemaps.map((item) => (
                                    <SelectItem key={item.id} value={item.id}>
                                        {basemapLabel(item.id, t)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="map-topbar-actions">
                        {viewing && mapId ? (
                            <>
                                <Button asChild variant="outline" size="sm" className="h-9 gap-1.5">
                                    <a href={`/maps/${mapId}/share`}>
                                        <Share2 className="size-4" />
                                        {t('actions.share')}
                                    </a>
                                </Button>
                                <Button asChild variant="outline" size="sm" className="h-9 gap-1.5">
                                    <a href={`/maps/builder/${mapId}`}>
                                        <Pencil className="size-4" />
                                        {t('actions.edit')}
                                    </a>
                                </Button>
                            </>
                        ) : null}
                        {viewing || layerMode ? null : (
                            <Button size="sm" className="h-9 gap-1.5" onClick={saveMap}>
                                <Save className="size-4" />
                                {t('actions.save')}
                            </Button>
                        )}
                        {layerMode ? null : (
                            <ButtonGroup>
                                <Button variant="outline" size="sm" className="h-9 gap-1.5" onClick={exportMap}>
                                    <Download className="size-4" />
                                    {t('actions.export')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-9 gap-1.5"
                                    onClick={printMap}
                                    disabled={!mapId}
                                    title={!mapId ? t('maps.save_before_print_hint') : t('actions.print')}
                                >
                                    <Printer className="size-4" />
                                    {t('actions.print')}
                                </Button>
                            </ButtonGroup>
                        )}
                    </div>
                </CardContent>
            </Card>

            {viewing ? null : (
            <aside className="map-side" data-tab={layerMode ? 'tools' : leftTab} aria-label={t('maps.workspace')}>
                {layerMode ? null : (
                    <div className="map-side-tabs" role="tablist" aria-label={t('maps.workspace')}>
                        <button
                            type="button"
                            role="tab"
                            className="map-side-tab"
                            aria-selected={leftTab === 'tools'}
                            onClick={() => setLeftTab('tools')}
                        >
                            {t('maps.tools')}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            className="map-side-tab"
                            aria-selected={leftTab === 'layers'}
                            onClick={() => setLeftTab('layers')}
                        >
                            {t('nav.layers')}
                        </button>
                    </div>
                )}
                <div className="map-side-body" role="tabpanel">
                    {layerMode || leftTab === 'tools' ? (
                        <ToolPanel
                            map={map}
                            mode={mode}
                            geometryType={layerMode ? selectedLayer?.geometry_type : null}
                            readOnly={Boolean(selectedLayer?.read_only)}
                            onToolSelected={handleToolSelection}
                        />
                    ) : (
                        <LayerPanel
                            lockComposition={layerMode}
                            onLayerSelected={() => setPanel(viewing ? 'legend' : 'style')}
                        />
                    )}
                </div>
            </aside>
            )}

            <div className="map-inspector-dock" role="group" aria-label={t('maps.inspector')}>
                {viewing ? (
                    <IconButton
                        variant={panel === 'layers' ? 'solid' : 'ghost'}
                        aria-label={t('nav.layers')}
                        aria-pressed={panel === 'layers'}
                        icon={<Layers className="size-4" strokeWidth={1.75} />}
                        onClick={() => openPanel('layers')}
                    />
                ) : null}
                <IconButton
                    variant={panel === 'legend' ? 'solid' : 'ghost'}
                    aria-label={t('maps.legend')}
                    aria-pressed={panel === 'legend'}
                    icon={<List className="size-4" strokeWidth={1.75} />}
                    onClick={() => openPanel('legend')}
                />
                <IconButton
                    variant={panel === 'properties' ? 'solid' : 'ghost'}
                    aria-label={t('maps.properties')}
                    aria-pressed={panel === 'properties'}
                    icon={<Info className="size-4" strokeWidth={1.75} />}
                    onClick={() => openPanel('properties')}
                />
                {viewing ? null : (
                    <>
                        <div className="dock-divider" aria-hidden="true" />
                        <IconButton
                            variant={panel === 'style' ? 'solid' : 'ghost'}
                            aria-label={t('layers.style')}
                            aria-pressed={panel === 'style'}
                            icon={<Palette className="size-4" strokeWidth={1.75} />}
                            onClick={() => openPanel('style')}
                        />
                        <IconButton
                            variant={panel === 'analysis' ? 'solid' : 'ghost'}
                            aria-label={t('maps.analysis')}
                            aria-pressed={panel === 'analysis'}
                            icon={<ChartColumn className="size-4" strokeWidth={1.75} />}
                            onClick={() => openPanel('analysis')}
                        />
                        <IconButton
                            variant={panel === 'assistant' ? 'solid' : 'ghost'}
                            aria-label={t('maps.assistant')}
                            aria-pressed={panel === 'assistant'}
                            icon={<Bot className="size-4" strokeWidth={1.75} />}
                            onClick={() => openPanel('assistant')}
                        />
                        {earlyWarningEnabled ? (
                            <IconButton
                                variant={panel === 'early-warning' ? 'solid' : 'ghost'}
                                aria-label={t('early_warning.title')}
                                aria-pressed={panel === 'early-warning'}
                                icon={<CloudRain className="size-4" strokeWidth={1.75} />}
                                onClick={() => openPanel('early-warning')}
                            />
                        ) : null}
                    </>
                )}
                {viewing && earlyWarningEnabled ? (
                    <IconButton
                        variant={panel === 'early-warning' ? 'solid' : 'ghost'}
                        aria-label={t('early_warning.title')}
                        aria-pressed={panel === 'early-warning'}
                        icon={<CloudRain className="size-4" strokeWidth={1.75} />}
                        onClick={() => openPanel('early-warning')}
                    />
                ) : null}
            </div>

            {panel ? (
                <aside className="map-float-panel" aria-label={t('maps.map_panel')} data-panel={panel}>
                    <div className="map-float-panel-body">
                        {panel === 'layers' && viewing ? <LayerPanel readOnly /> : null}
                        {panel === 'legend' && (
                            <>
                                <div className="panel-header">
                                    <h5>{t('maps.legend')}</h5>
                                    <button type="button" className="btn-close" onClick={closeRightPanel} aria-label={t('actions.close')} />
                                </div>
                                <Legend />
                            </>
                        )}
                        {panel === 'style' &&
                            (selectedLayer ? (
                                <StyleEditor />
                            ) : (
                                <>
                                    <div className="panel-header">
                                        <h5>{t('layers.style')}</h5>
                                        <button type="button" className="btn-close" onClick={closeRightPanel} aria-label={t('actions.close')} />
                                    </div>
                                    <p className="map-panel-empty">{t('maps.select_layer_style')}</p>
                                </>
                            ))}
                        {panel === 'analysis' && (
                            <AnalysisPanel
                                selectedLayer={selectedLayer}
                                selectedGeometry={drawnGeometryWkt}
                                onClose={() => setPanel(null)}
                                onAnalysisComplete={handleAnalysisComplete}
                            />
                        )}
                        {panel === 'assistant' && (
                            <GisAssistantPanel onClose={() => setPanel(null)} />
                        )}
                        {panel === 'early-warning' && earlyWarningEnabled && (
                            <Suspense fallback={<p className="map-panel-empty p-3">{t('maps.loading_early_warning')}</p>}>
                                <EarlyWarningPanel onClose={() => setPanel(null)} />
                            </Suspense>
                        )}
                        {panel === 'properties' &&
                            (dockMode ? (
                                <FeatureDock
                                    mode={dockMode}
                                    layer={selectedLayer}
                                    featureId={editingFeatureId}
                                    properties={featureProperties}
                                    onPropertiesChange={setFeatureProperties}
                                    geometryWkt={drawnGeometryWkt}
                                    onClose={closeDock}
                                    onSaved={handleDockSaved}
                                />
                            ) : (
                                <>
                                    <div className="panel-header">
                                        <h5>{drawerTitle}</h5>
                                        <button type="button" className="btn-close" onClick={closeRightPanel} aria-label={t('actions.close')} />
                                    </div>
                                    <p className="map-panel-empty">
                                        {identifyResult
                                            ? t('maps.identify_no_feature', {
                                                  lat: identifyResult.lat,
                                                  lon: identifyResult.lon,
                                              })
                                            : t('maps.identify_empty')}
                                    </p>
                                </>
                            ))}
                    </div>
                </aside>
            ) : null}

            <Card className="map-status-bar-card gap-0 py-0 shadow-sm" aria-label={t('maps.map_status')}>
                <CardContent className="map-status-bar flex items-center gap-2 p-2 pe-3 ps-3">
                    <Badge variant="secondary" className="shrink-0 rounded-md font-medium">
                        {statusLabel}
                    </Badge>
                    <Separator orientation="vertical" className="hidden h-4 sm:block" />
                    <span className="map-status-meta truncate font-mono text-[0.6875rem] text-muted-foreground tabular-nums" data-tabular>
                        {cursor ? `${cursor.lat}, ${cursor.lon}` : '—'}
                        {scaleText ? ` · ${scaleText}` : ''}
                        {measureResult?.type === 'distance'
                            ? ` · ${measureResult.distance_km} km`
                            : ''}
                        {measureResult?.type === 'area'
                            ? ` · ${measureResult.area_sqkm} km²`
                            : ''}
                    </span>
                </CardContent>
            </Card>
        </div>
    );
}
