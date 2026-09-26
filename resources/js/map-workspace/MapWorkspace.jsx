import { useEffect, useRef, useState } from 'react';
import Draw from 'ol/interaction/Draw';
import Modify from 'ol/interaction/Modify';
import VectorLayer from 'ol/layer/Vector';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import { Style, Stroke, Fill, Circle as CircleStyle } from 'ol/style';
import WKT from 'ol/format/WKT';
import { toLonLat } from 'ol/proj';
import { useMapStore } from './store/mapStore';
import MapView from './map/MapView';
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
import { ArrowLeft, Bot, ChartColumn, Download, Info, Layers, List, Palette, Pencil, Printer, Save, Share2, X } from 'lucide-react';

const SKIP_DETAIL_KEYS = new Set(['geometry', 'geom', 'the_geom', 'wkb_geometry', 'geojson']);

function featureAttributes(feature) {
    if (!feature || typeof feature !== 'object') {
        return { properties: {}, geojson: null };
    }

    const source = feature.properties && typeof feature.properties === 'object'
        ? feature.properties
        : feature;
    const geojson = feature.geojson || source.geojson || null;
    const properties = {};

    Object.entries(source).forEach(([key, value]) => {
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

const emptyProps = () => ({
    name: '',
    description: '',
    type: '',
    notes: '',
});

export default function MapWorkspace({ mode = 'edit' }) {
    const viewing = mode === 'view';
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
                layerName: hit.layer?.name || 'Feature',
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
        if (!layer || !featurePayload) {
            return null;
        }
        const source = layer.getSource();
        source.clear();
        const format = new GeoJSON();
        const olFeature = format.readFeature(featurePayload, {
            dataProjection: 'EPSG:4326',
            featureProjection: 'EPSG:3857',
        });
        source.addFeature(olFeature);
        currentFeatureRef.current = olFeature;
        return olFeature;
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

    const queryFeatureAtClick = async (event) => {
        const [lon, lat] = toLonLat(event.coordinate);
        const storeLayers = useMapStore.getState().layers || [];
        const selectedId = useMapStore.getState().selectedLayer;
        const candidates = [
            ...storeLayers.filter((layer) => layer.id === selectedId && layer.visible !== false),
            ...storeLayers.filter((layer) => layer.id !== selectedId && layer.visible !== false && layer.id),
        ];

        for (const layer of candidates) {
            try {
                const response = await fetch('/api/analysis/spatial-query', {
                    method: 'POST',
                    headers: jsonHeaders(),
                    body: JSON.stringify({
                        layer_id: layer.id,
                        operation: 'intersects',
                        wkt: `POINT(${lon} ${lat})`,
                    }),
                });
                const data = await response.json();
                if (data.success && data.features?.length) {
                    return { lon, lat, feature: data.features[0], layer };
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
            setIdentifyResult({
                lon: Number(hit.lon.toFixed(6)),
                lat: Number(hit.lat.toFixed(6)),
                layerId: selectedLayer?.id ?? null,
                feature: hit.feature,
            });

            if (hit.feature) {
                const id = hit.feature.id ?? hit.feature.properties?.id;
                const attributes = featureAttributes(hit.feature);
                setEditingFeatureId(id ?? null);
                setFeatureProperties(attributes.properties);
                setDrawnGeometryWkt(null);
                setDockMode('identify');
                setFeatureDetails({
                    layerName: hit.layer?.name || selectedLayer?.name || 'Feature',
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
            if (!hit.feature) {
                return;
            }

            const id = hit.feature.id ?? hit.feature.properties?.id;
            const olFeature = loadFeatureOntoMap(hit.feature);
            if (olFeature) {
                setDrawnGeometryWkt(syncWktFromFeature(olFeature));
                enableModify();
            }
            setEditingFeatureId(id ?? null);
            setFeatureProperties(hit.feature.properties || emptyProps());
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

    const handleDockSaved = () => {
        closeDock();
        if (drawLayerRef.current) {
            drawLayerRef.current.getSource().clear();
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
            alert('Save the map before printing.');
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
                throw new Error('Print failed');
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
            alert(error.message || 'Print failed');
        }
    };

    const saveMap = async () => {
        try {
            const name = prompt('Enter a name for your map:', mapName || 'My Map');
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

            alert('Map saved successfully! Redirecting...');

            const savedId = response.data?.map?.id || mapId;
            if (savedId) {
                window.location.href = `/maps/${savedId}`;
            }
        } catch (error) {
            console.error('Error saving map:', error);
            if (error.response) {
                alert(
                    `Failed to save map: ${error.response.data.message || error.response.statusText}`
                );
            } else if (error.request) {
                alert('Failed to save map: No response from server. Please check your connection.');
            } else {
                alert(`Failed to save map: ${error.message}`);
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
        ? 'New feature'
        : dockMode === 'edit'
          ? 'Edit feature'
          : dockMode === 'identify'
            ? 'Identified feature'
            : 'Attributes';

    const statusLabel = measureResult?.type
        ? 'Measure'
        : dockMode
          ? drawerTitle
          : 'Ready';

    return (
        <div className="map-builder map-stage">
            <div className="map-viewport">
                <MapView />
                <TemporalSlider />
                {featureDetails && (
                    <Card className="map-feature-details gap-0 py-0 shadow-lg">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground">DETAILS</p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={() => setFeatureDetails(null)}
                            >
                                <X className="size-4" />
                                <span className="sr-only">Close details</span>
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
                                        <TableHead className="px-4">Field Name</TableHead>
                                        <TableHead className="px-4">Value</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {Object.entries(featureDetails.properties).length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={2} className="px-4 text-muted-foreground">
                                                No attributes
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
                            <a href="/maps">
                                <ArrowLeft className="size-4" />
                                Maps
                            </a>
                        </Button>
                        <Separator orientation="vertical" className="hidden h-5 sm:block" />
                        <p className="map-title truncate text-sm font-semibold tracking-tight">
                            {mapName || 'Map'}
                        </p>
                    </div>
                    <div className="map-topbar-center">
                        <GeocodeSearch />
                        <Select value={basemap} onValueChange={setBasemap}>
                            <SelectTrigger
                                size="sm"
                                className="map-basemap h-9 w-[11.5rem] shrink-0 bg-background shadow-xs"
                                aria-label="Basemap"
                            >
                                <SelectValue placeholder="Basemap" />
                            </SelectTrigger>
                            <SelectContent>
                                {availableBasemaps.map((item) => (
                                    <SelectItem key={item.id} value={item.id}>
                                        {item.name}
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
                                        Share
                                    </a>
                                </Button>
                                <Button asChild variant="outline" size="sm" className="h-9 gap-1.5">
                                    <a href={`/maps/builder/${mapId}`}>
                                        <Pencil className="size-4" />
                                        Edit
                                    </a>
                                </Button>
                            </>
                        ) : null}
                        {viewing ? null : (
                            <Button size="sm" className="h-9 gap-1.5" onClick={saveMap}>
                                <Save className="size-4" />
                                Save
                            </Button>
                        )}
                        <ButtonGroup>
                            <Button variant="outline" size="sm" className="h-9 gap-1.5" onClick={exportMap}>
                                <Download className="size-4" />
                                Export
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-9 gap-1.5"
                                onClick={printMap}
                                disabled={!mapId}
                                title={!mapId ? 'Save the map before printing' : 'Print'}
                            >
                                <Printer className="size-4" />
                                Print
                            </Button>
                        </ButtonGroup>
                    </div>
                </CardContent>
            </Card>

            {viewing ? null : (
            <aside className="map-side" data-tab={leftTab} aria-label="Map workspace">
                <div className="map-side-tabs" role="tablist" aria-label="Workspace">
                    <button
                        type="button"
                        role="tab"
                        className="map-side-tab"
                        aria-selected={leftTab === 'tools'}
                        onClick={() => setLeftTab('tools')}
                    >
                        Tools
                    </button>
                    <button
                        type="button"
                        role="tab"
                        className="map-side-tab"
                        aria-selected={leftTab === 'layers'}
                        onClick={() => setLeftTab('layers')}
                    >
                        Layers
                    </button>
                </div>
                <div className="map-side-body" role="tabpanel">
                    {leftTab === 'tools' ? (
                        <ToolPanel map={map} mode={mode} onToolSelected={handleToolSelection} />
                    ) : (
                        <LayerPanel
                            onLayerSelected={() => setPanel(viewing ? 'legend' : 'style')}
                        />
                    )}
                </div>
            </aside>
            )}

            <div className="map-inspector-dock" role="group" aria-label="Inspector">
                {viewing ? (
                    <IconButton
                        variant={panel === 'layers' ? 'solid' : 'ghost'}
                        aria-label="Layers"
                        aria-pressed={panel === 'layers'}
                        icon={<Layers className="size-4" strokeWidth={1.75} />}
                        onClick={() => openPanel('layers')}
                    />
                ) : null}
                <IconButton
                    variant={panel === 'legend' ? 'solid' : 'ghost'}
                    aria-label="Legend"
                    aria-pressed={panel === 'legend'}
                    icon={<List className="size-4" strokeWidth={1.75} />}
                    onClick={() => openPanel('legend')}
                />
                <IconButton
                    variant={panel === 'properties' ? 'solid' : 'ghost'}
                    aria-label="Properties"
                    aria-pressed={panel === 'properties'}
                    icon={<Info className="size-4" strokeWidth={1.75} />}
                    onClick={() => openPanel('properties')}
                />
                {viewing ? null : (
                    <>
                        <div className="dock-divider" aria-hidden="true" />
                        <IconButton
                            variant={panel === 'style' ? 'solid' : 'ghost'}
                            aria-label="Style"
                            aria-pressed={panel === 'style'}
                            icon={<Palette className="size-4" strokeWidth={1.75} />}
                            onClick={() => openPanel('style')}
                        />
                        <IconButton
                            variant={panel === 'analysis' ? 'solid' : 'ghost'}
                            aria-label="Analysis"
                            aria-pressed={panel === 'analysis'}
                            icon={<ChartColumn className="size-4" strokeWidth={1.75} />}
                            onClick={() => openPanel('analysis')}
                        />
                        <IconButton
                            variant={panel === 'assistant' ? 'solid' : 'ghost'}
                            aria-label="Assistant"
                            aria-pressed={panel === 'assistant'}
                            icon={<Bot className="size-4" strokeWidth={1.75} />}
                            onClick={() => openPanel('assistant')}
                        />
                    </>
                )}
            </div>

            {panel ? (
                <aside className="map-float-panel" aria-label="Map panel" data-panel={panel}>
                    <div className="map-float-panel-body">
                        {panel === 'layers' && viewing ? <LayerPanel readOnly /> : null}
                        {panel === 'legend' && (
                            <>
                                <div className="panel-header">
                                    <h5>Legend</h5>
                                    <button type="button" className="btn-close" onClick={closeRightPanel} aria-label="Close" />
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
                                        <h5>Style</h5>
                                        <button type="button" className="btn-close" onClick={closeRightPanel} aria-label="Close" />
                                    </div>
                                    <p className="map-panel-empty">Select a layer to edit its style.</p>
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
                                        <button type="button" className="btn-close" onClick={closeRightPanel} aria-label="Close" />
                                    </div>
                                    <p className="map-panel-empty">
                                        {identifyResult
                                            ? `No feature at ${identifyResult.lat}, ${identifyResult.lon}. Choose a layer, then identify again.`
                                            : 'Identify or select a feature to see its attributes here.'}
                                    </p>
                                </>
                            ))}
                    </div>
                </aside>
            ) : null}

            <Card className="map-status-bar-card gap-0 py-0 shadow-sm" aria-label="Map status">
                <CardContent className="map-status-bar flex items-center gap-2 p-2 pr-3 pl-3">
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
