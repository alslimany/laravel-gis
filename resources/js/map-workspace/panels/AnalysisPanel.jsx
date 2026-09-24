import { useState } from 'react';
import { Download, FileSpreadsheet, Image, Map, Plus, Search, X } from 'lucide-react';
import { useMapStore } from '../store/mapStore';
import { getCsrfToken } from '../utils/csrf';

function publishAnalysisResult(payload, setAnalysisResults, onAnalysisComplete) {
    setAnalysisResults?.(payload);
    window.dispatchEvent(new CustomEvent('gis:analysis-result', { detail: payload }));
    onAnalysisComplete?.(payload);
}

export default function AnalysisPanel({ selectedLayer, selectedGeometry, onClose, onAnalysisComplete }) {
    const map = useMapStore((state) => state.map);
    const mapId = useMapStore((state) => state.mapId);
    const layers = useMapStore((state) => state.layers);
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
            alert('Please draw or select a geometry first');
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

            if (data.success) {
                publishAnalysisResult(
                    { type: 'buffer', result: data, geojson: data.geojson || data.result },
                    setAnalysisResults,
                    onAnalysisComplete
                );
            }
        } catch (error) {
            console.error('Buffer analysis failed:', error);
            alert('Buffer analysis failed');
        }
    };

    const performSpatialQuery = async () => {
        if (!selectedLayer || !selectedGeometry) {
            alert('Please select a layer and draw a geometry');
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

            if (data.success) {
                setResults(data);
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
            }
        } catch (error) {
            console.error('Spatial query failed:', error);
            alert('Spatial query failed');
        }
    };

    const performAttributeQuery = async () => {
        if (!selectedLayer) {
            alert('Please select a layer');
            return;
        }

        const validConditions = queryConditions.filter((c) => c.column && c.value);

        if (validConditions.length === 0) {
            alert('Please add at least one valid condition');
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

            if (data.success) {
                setResults(data);
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
            }
        } catch (error) {
            console.error('Attribute query failed:', error);
            alert('Attribute query failed');
        }
    };

    const performOverlay = async () => {
        if (!selectedLayer?.id || !overlayLayerB) {
            alert('Select both input layers for the overlay');
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
                          : 'Overlay failed'
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
            alert(error.message || 'Overlay failed');
        } finally {
            setOverlayBusy(false);
        }
    };

    const exportGeoJSON = () => {
        if (!selectedLayer) {
            alert('Please select a layer to export');
            return;
        }

        window.location.href = `/export/layer/${selectedLayer.id}/geojson`;
    };

    const exportCSV = () => {
        if (!selectedLayer) {
            alert('Please select a layer to export');
            return;
        }

        window.location.href = `/export/layer/${selectedLayer.id}/csv`;
    };

    const exportMapConfig = () => {
        if (!mapId) {
            alert('Please save the map first');
            return;
        }

        window.location.href = `/export/map/${mapId}/config`;
    };

    const exportMapImage = () => {
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
                            .match(/^matrix\(([^()]*)\)$/)[1]
                            .split(',')
                            .map(Number);
                        CanvasRenderingContext2D.prototype.setTransform.apply(mapContext, matrix);
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

    const otherLayers = layers.filter((layer) => layer.id !== selectedLayer?.id);

    return (
        <div className="analysis-panel">
            <div className="panel-header">
                <h4>Analysis Tools</h4>
                <button type="button" onClick={onClose} className="close-btn">
                    <X className="glyph" strokeWidth={1.75} aria-hidden="true" />
                </button>
            </div>

            <div className="panel-body">
                <div className="tool-section">
                    <h5>Spatial Analysis</h5>

                    <div className="tool-item">
                        <label>Buffer Analysis</label>
                        <div className="input-group">
                            <input
                                value={bufferDistance}
                                onChange={(event) => setBufferDistance(Number(event.target.value))}
                                type="number"
                                placeholder="Distance (meters)"
                                className="form-control"
                            />
                            <button type="button" onClick={performBuffer} className="btn btn-sm btn-primary">
                                <Search className="glyph" strokeWidth={1.75} aria-hidden="true" /> Buffer
                            </button>
                        </div>
                    </div>

                    <div className="tool-item">
                        <label>Spatial Query</label>
                        <select
                            value={spatialOperation}
                            onChange={(event) => setSpatialOperation(event.target.value)}
                            className="form-control mb-2"
                        >
                            <option value="within">Within</option>
                            <option value="contains">Contains</option>
                            <option value="intersects">Intersects</option>
                        </select>
                        <button
                            type="button"
                            onClick={performSpatialQuery}
                            className="btn btn-sm btn-primary w-100"
                        >
                            <Search className="glyph" strokeWidth={1.75} aria-hidden="true" /> Query Features
                        </button>
                    </div>

                    <div className="tool-item">
                        <label>Overlay (union / intersect / erase)</label>
                        <select
                            value={overlayOp}
                            onChange={(event) => setOverlayOp(event.target.value)}
                            className="form-control mb-2"
                        >
                            <option value="union">Union</option>
                            <option value="intersect">Intersect</option>
                            <option value="erase">Erase</option>
                        </select>
                        <select
                            value={overlayLayerB}
                            onChange={(event) => setOverlayLayerB(event.target.value)}
                            className="form-control mb-2"
                        >
                            <option value="">Second layer…</option>
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
                            placeholder="Result layer name (optional)"
                        />
                        <label className="form-check mb-2">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                checked={writeResult}
                                onChange={(event) => setWriteResult(event.target.checked)}
                            />
                            <span className="form-check-label">Write result as a new layer</span>
                        </label>
                        <button
                            type="button"
                            onClick={performOverlay}
                            className="btn btn-sm btn-primary w-100"
                            disabled={overlayBusy}
                        >
                            <Search className="glyph" strokeWidth={1.75} aria-hidden="true" />{' '}
                            {overlayBusy ? 'Running…' : 'Run overlay'}
                        </button>
                    </div>
                </div>

                <div className="tool-section">
                    <h5>Attribute Query</h5>
                    <div className="tool-item">
                        <div className="query-builder">
                            {queryConditions.map((condition, index) => (
                                <div key={index} className="condition-row">
                                    <input
                                        value={condition.column}
                                        onChange={(event) =>
                                            updateCondition(index, 'column', event.target.value)
                                        }
                                        placeholder="Column"
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
                                        placeholder="Value"
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
                            <Plus className="glyph" strokeWidth={1.75} aria-hidden="true" /> Add Condition
                        </button>
                        <button
                            type="button"
                            onClick={performAttributeQuery}
                            className="btn btn-sm btn-primary w-100"
                        >
                            <Search className="glyph" strokeWidth={1.75} aria-hidden="true" /> Execute Query
                        </button>
                    </div>
                </div>

                <div className="tool-section">
                    <h5>Export</h5>
                    <div className="tool-item">
                        <button type="button" onClick={exportGeoJSON} className="btn btn-sm btn-success w-100 mb-2">
                            <Download className="glyph" strokeWidth={1.75} aria-hidden="true" /> Export as GeoJSON
                        </button>
                        <button type="button" onClick={exportCSV} className="btn btn-sm btn-success w-100 mb-2">
                            <FileSpreadsheet className="glyph" strokeWidth={1.75} aria-hidden="true" /> Export as CSV
                        </button>
                        <button type="button" onClick={exportMapConfig} className="btn btn-sm btn-info w-100 mb-2">
                            <Map className="glyph" strokeWidth={1.75} aria-hidden="true" /> Export Map Config
                        </button>
                        <button type="button" onClick={exportMapImage} className="btn btn-sm btn-warning w-100">
                            <Image className="glyph" strokeWidth={1.75} aria-hidden="true" /> Export as Image
                        </button>
                    </div>
                </div>

                {results && (
                    <div className="tool-section">
                        <h5>Results</h5>
                        <div className="results-display">
                            <p>
                                <strong>Count:</strong> {results.count}
                            </p>
                            {results.features && (
                                <div className="feature-list">
                                    {results.features.slice(0, 5).map((_, index) => (
                                        <div key={index} className="feature-item">
                                            Feature {index + 1}
                                        </div>
                                    ))}
                                    {results.features.length > 5 && (
                                        <p className="text-muted">
                                            ... and {results.features.length - 5} more
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
