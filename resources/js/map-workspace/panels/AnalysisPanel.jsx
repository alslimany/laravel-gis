import { useEffect, useState } from 'react';
import { Download, FileSpreadsheet, Image, Map, Plus, Search, X } from 'lucide-react';
import { useMapStore } from '../store/mapStore';
import { getCsrfToken } from '../utils/csrf';

import { __ } from '@/lib/i18n';
function publishAnalysisResult(payload, setAnalysisResults, onAnalysisComplete) {
    setAnalysisResults?.(payload);
    window.dispatchEvent(new CustomEvent('gis:analysis-result', { detail: payload }));
    onAnalysisComplete?.(payload);
}

function featureIds(features) {
    if (!Array.isArray(features)) {
        return [];
    }

    const ids = [];
    features.forEach((feature) => {
        const raw = feature?.id ?? feature?.properties?.id;
        const numeric = Number(raw);
        if (Number.isInteger(numeric) && numeric > 0) {
            ids.push(numeric);
        }
    });

    return [...new Set(ids)];
}

function analysisError(data) {
    if (!data) {
        return __('analysis.failed');
    }
    if (typeof data.error === 'string' && data.error) {
        return data.error;
    }
    if (typeof data.message === 'string' && data.message) {
        return data.message;
    }
    return __('analysis.failed');
}

async function downloadExport(url, fallbackName) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json, application/geo+json, text/csv, */*' },
    });
    if (!response.ok) {
        let message = __('analysis.export_failed');
        try {
            const body = await response.json();
            if (typeof body.error === 'string' && body.error) {
                message = body.error;
            }
        } catch {
            message = __('analysis.export_failed');
        }
        alert(message);
        return;
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const anchor = document.createElement('a');
    anchor.href = URL.createObjectURL(blob);
    anchor.download = match?.[1] || fallbackName;
    anchor.click();
    URL.revokeObjectURL(anchor.href);
}

export default function AnalysisPanel({ selectedLayer, selectedGeometry, onClose, onAnalysisComplete }) {
    const map = useMapStore((state) => state.map);
    const mapId = useMapStore((state) => state.mapId);
    const layers = useMapStore((state) => state.layers);
    const viewport = useMapStore((state) => state.viewport);
    const basemap = useMapStore((state) => state.basemap);
    const setAnalysisResults = useMapStore((state) => state.setAnalysisResults);

    const [bufferDistance, setBufferDistance] = useState(100);
    const [spatialOperation, setSpatialOperation] = useState('within');
    const [overlayOp, setOverlayOp] = useState('union');
    const [overlayLayerB, setOverlayLayerB] = useState('');
    const [writeResult, setWriteResult] = useState(true);
    const [resultName, setResultName] = useState('');
    const [overlayBusy, setOverlayBusy] = useState(false);
    const [queryConditions, setQueryConditions] = useState([
        { column: '', operator: '=', value: '' },
    ]);
    const [results, setResults] = useState(null);
    const [resultKind, setResultKind] = useState('query');
    const [savedName, setSavedName] = useState('');
    const [saveState, setSaveState] = useState(null);
    const [savedAnalysisId, setSavedAnalysisId] = useState(null);
    const [savedLayer, setSavedLayer] = useState(null);

    useEffect(() => {
        setSavedAnalysisId(null);
        setSavedLayer(null);
        setSaveState(null);
    }, [results]);

    const addCondition = () => {
        setQueryConditions((prev) => [...prev, { column: '', operator: '=', value: '' }]);
    };

    const removeCondition = (index) => {
        setQueryConditions((prev) => prev.filter((_, i) => i !== index));
    };

    const updateCondition = (index, field, value) => {
        setQueryConditions((prev) =>
            prev.map((condition, i) =>
                i === index ? { ...condition, [field]: value } : condition
            )
        );
    };

    const performBuffer = async () => {
        if (!selectedGeometry) {
            alert(__('analysis.need_geometry'));
            return;
        }

        try {
            const response = await fetch('/api/analysis/buffer', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    wkt: selectedGeometry,
                    distance: bufferDistance,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                alert(analysisError(data));
                return;
            }

            publishAnalysisResult(
                { type: 'buffer', result: data, geojson: data.geojson || data.result },
                setAnalysisResults,
                onAnalysisComplete
            );
        } catch (error) {
            console.error('Buffer analysis failed:', error);
            alert(__('analysis.buffer_failed'));
        }
    };

    const performSpatialQuery = async () => {
        if (!selectedLayer || !selectedGeometry) {
            alert(__('analysis.need_layer_geometry'));
            return;
        }

        try {
            const response = await fetch('/api/analysis/spatial-query', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    layer_id: selectedLayer.id,
                    operation: spatialOperation,
                    wkt: selectedGeometry,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                alert(analysisError(data));
                return;
            }

            setResults(data);
            setResultKind('spatial_query');
            setSaveState(null);
            publishAnalysisResult(
                {
                    type: 'spatial-query',
                    result: data,
                    geojson: data.geojson || {
                        type: 'FeatureCollection',
                        features: data.features || [],
                    },
                },
                setAnalysisResults,
                onAnalysisComplete
            );
        } catch (error) {
            console.error('Spatial query failed:', error);
            alert(__('analysis.spatial_failed'));
        }
    };

    const performAttributeQuery = async () => {
        if (!selectedLayer) {
            alert(__('analysis.need_layer'));
            return;
        }

        const validConditions = queryConditions.filter((c) => c.column && c.value);

        if (validConditions.length === 0) {
            alert(__('analysis.need_condition'));
            return;
        }

        try {
            const response = await fetch('/api/analysis/attribute-query', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    layer_id: selectedLayer.id,
                    conditions: validConditions,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                alert(analysisError(data));
                return;
            }

            setResults(data);
            setResultKind('attribute_query');
            setSaveState(null);
            publishAnalysisResult(
                {
                    type: 'attribute-query',
                    result: data,
                    geojson: data.geojson || {
                        type: 'FeatureCollection',
                        features: data.features || [],
                    },
                },
                setAnalysisResults,
                onAnalysisComplete
            );
        } catch (error) {
            console.error('Attribute query failed:', error);
            alert(__('analysis.attr_failed'));
        }
    };

    const performOverlay = async () => {
        if (!selectedLayer?.id || !overlayLayerB) {
            alert(__('analysis.need_two_layers'));
            return;
        }

        setOverlayBusy(true);
        try {
            const response = await fetch(`/api/analysis/${overlayOp}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    layer_id_a: selectedLayer.id,
                    layer_id_b: Number(overlayLayerB),
                    write_result: writeResult,
                    result_name: resultName || null,
                }),
            });
            const data = await response.json();
            if (!response.ok || data.error) {
                throw new Error(
                    typeof data.error === 'string'
                        ? data.error
                        : data.error
                          ? JSON.stringify(data.error)
                          : __('analysis.overlay_failed')
                );
            }
            setResults({ count: data.count, features: data.geojson?.features || [] });
            publishAnalysisResult(
                {
                    type: 'overlay',
                    operation: overlayOp,
                    geojson: data.geojson,
                    result_layer: data.result_layer,
                    count: data.count,
                },
                setAnalysisResults,
                onAnalysisComplete
            );
        } catch (error) {
            console.error(error);
            alert(error.message || __('analysis.overlay_failed'));
        } finally {
            setOverlayBusy(false);
        }
    };

    const exportGeoJSON = () => {
        if (!selectedLayer) {
            alert(__('analysis.export_need_layer'));
            return;
        }

        downloadExport(`/export/layer/${selectedLayer.id}/geojson`, `${selectedLayer.name || 'layer'}.geojson`);
    };

    const exportCSV = () => {
        if (!selectedLayer) {
            alert(__('analysis.export_need_layer'));
            return;
        }

        downloadExport(`/export/layer/${selectedLayer.id}/csv`, `${selectedLayer.name || 'layer'}.csv`);
    };

    const exportMapConfig = () => {
        if (!mapId) {
            const blob = new Blob([JSON.stringify({ viewport, basemap, layers }, null, 2)], {
                type: 'application/json',
            });
            const anchor = document.createElement('a');
            anchor.href = URL.createObjectURL(blob);
            anchor.download = 'map-config.json';
            anchor.click();
            URL.revokeObjectURL(anchor.href);
            return;
        }

        downloadExport(`/export/map/${mapId}/config`, 'map-config.json');
    };

    const exportMapImage = () => {
        if (!map) {
            alert(__('analysis.map_unavailable'));
            return;
        }

        map.once('rendercomplete', () => {
            try {
                const mapCanvas = document.createElement('canvas');
                const size = map.getSize();
                if (!size || !size[0] || !size[1]) {
                    alert(__('analysis.map_not_ready'));
                    return;
                }
                mapCanvas.width = size[0];
                mapCanvas.height = size[1];
                const mapContext = mapCanvas.getContext('2d');
                if (!mapContext) {
                    alert(__('analysis.image_failed'));
                    return;
                }

                Array.prototype.forEach.call(
                    document.querySelectorAll('.ol-layer canvas'),
                    (canvas) => {
                        if (canvas.width <= 0) {
                            return;
                        }
                        const opacity = canvas.parentNode.style.opacity;
                        mapContext.globalAlpha = opacity === '' ? 1 : Number(opacity);
                        const transform = canvas.style.transform || '';
                        const matched = transform.match(/^matrix\(([^()]*)\)$/);
                        if (matched) {
                            const matrix = matched[1].split(',').map(Number);
                            CanvasRenderingContext2D.prototype.setTransform.apply(mapContext, matrix);
                        } else {
                            mapContext.setTransform(1, 0, 0, 1, 0, 0);
                        }
                        mapContext.drawImage(canvas, 0, 0);
                    }
                );

                mapCanvas.toBlob((blob) => {
                    if (!blob) {
                        alert(__('analysis.image_failed'));
                        return;
                    }
                    const link = document.createElement('a');
                    link.download = `map_${Date.now()}.png`;
                    link.href = URL.createObjectURL(blob);
                    link.click();
                    URL.revokeObjectURL(link.href);
                });
            } catch (error) {
                console.error(error);
                alert(__('analysis.image_failed'));
            }
        });

        map.renderSync();
    };

    const saveResult = async () => {
        const analysisId = await persistResult();
        if (analysisId) {
            setSaveState(__('analysis.saved_for_dash', { name: savedName.trim() }));
        }
    };

    const persistResult = async () => {
        if (savedAnalysisId) {
            return savedAnalysisId;
        }

        const ids = featureIds(results?.features);
        const name = savedName.trim();
        if (!selectedLayer || ids.length === 0) {
            alert(__('analysis.need_result'));
            return null;
        }
        if (!name) {
            alert(__('analysis.need_name'));
            return null;
        }

        try {
            const response = await fetch('/api/analysis/results', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    name,
                    layer_id: selectedLayer.id,
                    kind: resultKind,
                    feature_ids: ids.slice(0, 2000),
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                alert(analysisError(data));
                return null;
            }
            setSavedAnalysisId(data.analysis?.id ?? null);
            return data.analysis?.id ?? null;
        } catch (error) {
            console.error(error);
            alert(__('analysis.save_failed'));
            return null;
        }
    };

    const saveAsLayer = async () => {
        const analysisId = await persistResult();
        if (!analysisId) {
            return;
        }

        try {
            const response = await fetch(`/api/analysis/results/${analysisId}/save-as-layer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ name: savedName.trim() }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                alert(analysisError(data));
                return;
            }
            setSavedLayer(data.layer ?? null);
            setSaveState(__('analysis.layer_created', { name: data.layer?.name ?? '' }));
        } catch (error) {
            console.error(error);
            alert(__('analysis.save_layer_failed'));
        }
    };

    const otherLayers = layers.filter((layer) => layer.id !== selectedLayer?.id);
    const savableIds = featureIds(results?.features);

    return (
        <div className="analysis-panel">
            <div className="panel-header">
                <h4>{__('maps.analysis_tools')}</h4>
                <button type="button" onClick={onClose} className="close-btn">
                    <X className="glyph" strokeWidth={1.75} aria-hidden="true" />
                </button>
            </div>

            <div className="panel-body">
                <div className="tool-section">
                    <h5>{__('maps.spatial_analysis')}</h5>

                    <div className="tool-item">
                        <label>{__('maps.buffer_analysis')}</label>
                        <div className="input-group">
                            <input
                                value={bufferDistance}
                                onChange={(event) => setBufferDistance(Number(event.target.value))}
                                type="number"
                                placeholder={__('maps.distance_m')}
                                className="form-control"
                            />
                            <button type="button" onClick={performBuffer} className="btn btn-sm btn-primary">
                                <Search className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.buffer')}
                            </button>
                        </div>
                    </div>

                    <div className="tool-item">
                        <label>{__('maps.spatial_query')}</label>
                        <select
                            value={spatialOperation}
                            onChange={(event) => setSpatialOperation(event.target.value)}
                            className="form-control mb-2"
                        >
                            <option value="within">{__('maps.within')}</option>
                            <option value="contains">{__('maps.contains')}</option>
                            <option value="intersects">{__('maps.intersects')}</option>
                        </select>
                        <button
                            type="button"
                            onClick={performSpatialQuery}
                            className="btn btn-sm btn-primary w-100"
                        >
                            <Search className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.query_features')}
                        </button>
                    </div>

                    <div className="tool-item">
                        <label>{__('maps.overlay')}</label>
                        <select
                            value={overlayOp}
                            onChange={(event) => setOverlayOp(event.target.value)}
                            className="form-control mb-2"
                        >
                            <option value="union">{__('maps.union')}</option>
                            <option value="intersect">{__('maps.intersect')}</option>
                            <option value="erase">{__('maps.erase')}</option>
                        </select>
                        <select
                            value={overlayLayerB}
                            onChange={(event) => setOverlayLayerB(event.target.value)}
                            className="form-control mb-2"
                        >
                            <option value="">{__('maps.second_layer')}</option>
                            {otherLayers.map((layer) => (
                                <option key={layer.id} value={layer.id}>
                                    {layer.name}
                                </option>
                            ))}
                        </select>
                        <input
                            value={resultName}
                            onChange={(event) => setResultName(event.target.value)}
                            className="form-control mb-2"
                            placeholder={__('maps.result_name')}
                        />
                        <label className="form-check mb-2">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                checked={writeResult}
                                onChange={(event) => setWriteResult(event.target.checked)}
                            />
                            <span className="form-check-label">{__('maps.write_new_layer')}</span>
                        </label>
                        <button
                            type="button"
                            onClick={performOverlay}
                            className="btn btn-sm btn-primary w-100"
                            disabled={overlayBusy}
                        >
                            <Search className="glyph" strokeWidth={1.75} aria-hidden="true" />{' '}
                            {overlayBusy ? __('analysis.running') : __('analysis.run_overlay')}
                        </button>
                    </div>
                </div>

                <div className="tool-section">
                    <h5>{__('maps.attribute_query')}</h5>
                    <div className="tool-item">
                        <div className="query-builder">
                            {queryConditions.map((condition, index) => (
                                <div key={index} className="condition-row">
                                    <input
                                        value={condition.column}
                                        onChange={(event) =>
                                            updateCondition(index, 'column', event.target.value)
                                        }
                                        placeholder={__('maps.column')}
                                        className="form-control form-control-sm"
                                    />
                                    <select
                                        value={condition.operator}
                                        onChange={(event) =>
                                            updateCondition(index, 'operator', event.target.value)
                                        }
                                        className="form-control form-control-sm"
                                    >
                                        <option value="=">=</option>
                                        <option value="!=">!=</option>
                                        <option value=">">&gt;</option>
                                        <option value="<">&lt;</option>
                                        <option value=">=">&gt;=</option>
                                        <option value="<=">&lt;=</option>
                                        <option value="LIKE">LIKE</option>
                                    </select>
                                    <input
                                        value={condition.value}
                                        onChange={(event) =>
                                            updateCondition(index, 'value', event.target.value)
                                        }
                                        placeholder={__('maps.value')}
                                        className="form-control form-control-sm"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => removeCondition(index)}
                                        className="btn btn-sm btn-danger"
                                    >
                                        <X className="glyph" strokeWidth={1.75} aria-hidden="true" />
                                    </button>
                                </div>
                            ))}
                        </div>
                        <button type="button" onClick={addCondition} className="btn btn-sm btn-secondary mb-2">
                            <Plus className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.add_condition')}
                        </button>
                        <button
                            type="button"
                            onClick={performAttributeQuery}
                            className="btn btn-sm btn-primary w-100"
                        >
                            <Search className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.execute_query')}
                        </button>
                    </div>
                </div>

                <div className="tool-section">
                    <h5>{__('maps.export')}</h5>
                    <div className="tool-item">
                        <button type="button" onClick={exportGeoJSON} className="btn btn-sm btn-success w-100 mb-2">
                            <Download className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.export_geojson')}
                        </button>
                        <button type="button" onClick={exportCSV} className="btn btn-sm btn-success w-100 mb-2">
                            <FileSpreadsheet className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.export_csv')}
                        </button>
                        <button type="button" onClick={exportMapConfig} className="btn btn-sm btn-info w-100 mb-2">
                            <Map className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.export_config')}
                        </button>
                        <button type="button" onClick={exportMapImage} className="btn btn-sm btn-warning w-100">
                            <Image className="glyph" strokeWidth={1.75} aria-hidden="true" /> {__('maps.export_image')}
                        </button>
                    </div>
                </div>

                {results && (
                    <div className="tool-section">
                        <h5>{__('maps.results')}</h5>
                        <div className="results-display">
                            <p>
                                <strong>{__('maps.count_colon')}</strong> {results.count}
                            </p>
                            {savableIds.length > 0 && selectedLayer ? (
                                <div className="mt-2">
                                    <input
                                        value={savedName}
                                        onChange={(event) => setSavedName(event.target.value)}
                                        className="form-control form-control-sm mb-2"
                                        placeholder={__('maps.name_result')}
                                        aria-label={__('maps.saved_analysis_name')}
                                    />
                                    <button type="button" onClick={saveResult} className="btn btn-sm btn-secondary w-100">
                                        {__('maps.save_dashboards')}
                                    </button>
                                    <button type="button" onClick={saveAsLayer} className="btn btn-sm btn-primary w-100 mt-2">
                                        {__('maps.save_as_layer')}
                                    </button>
                                    {savedLayer ? (
                                        <p className="text-muted mt-2 mb-0">
                                            <a href={`/layers/${savedLayer.id}`}>{savedLayer.name}</a>
                                        </p>
                                    ) : null}
                                    {saveState ? <p className="text-muted mt-2 mb-0">{saveState}</p> : null}
                                </div>
                            ) : null}
                            {results.features && (
                                <div className="feature-list">
                                    {results.features.slice(0, 5).map((_, index) => (
                                        <div key={index} className="feature-item">
                                            {__('maps.feature_n', { n: index + 1 })}
                                        </div>
                                    ))}
                                    {results.features.length > 5 && (
                                        <p className="text-muted">
                                            {__('maps.and_more', { n: results.features.length - 5 })}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
