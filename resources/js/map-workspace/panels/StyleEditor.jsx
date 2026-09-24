import { useEffect, useMemo, useState } from 'react';
import { Check, Loader2, Palette, RefreshCw, Undo2 } from 'lucide-react';
import { useMapStore } from '../store/mapStore';
import { getCsrfToken, jsonHeaders } from '../utils/csrf';
import { POINT_ICONS } from '../icons/pointIcons';
import {
    COLOR_RAMPS,
    buildEqualIntervalClasses,
    normalizeStyleConfig,
} from '../utils/styleRenderers';

const defaultSymbol = {
    fillColor: '#3388ff',
    fillOpacity: 0.6,
    strokeColor: '#3388ff',
    strokeWidth: 2,
    strokeOpacity: 1,
    pointRadius: 6,
    icon: 'circle',
    size: 24,
};

const RENDERERS = [
    { id: 'simple', label: 'Simple' },
    { id: 'unique', label: 'Unique values' },
    { id: 'graduated', label: 'Graduated' },
    { id: 'heatmap', label: 'Heatmap' },
];

function emptyForm() {
    return {
        renderer: 'simple',
        field: '',
        colorRamp: 'blues',
        classCount: 5,
        classMin: 0,
        classMax: 100,
        uniqueValues: [],
        classes: [],
        symbol: { ...defaultSymbol },
        labels: { enabled: false, field: '', color: '#222222' },
        heatmap: { blur: 15, radius: 8, weight: '' },
        opacity: 1,
        legend: [],
    };
}

function formFromLayer(layer) {
    const config = normalizeStyleConfig(layer);
    return {
        renderer: config.renderer || 'simple',
        field: config.field || '',
        colorRamp: config.colorRamp || 'blues',
        classCount: config.classCount || config.classes?.length || 5,
        classMin: config.classes?.[0]?.min ?? 0,
        classMax: config.classes?.[config.classes.length - 1]?.max ?? 100,
        uniqueValues: config.uniqueValues || [],
        classes: config.classes || [],
        symbol: { ...defaultSymbol, ...config.symbol },
        labels: {
            enabled: Boolean(config.labels?.enabled),
            field: config.labels?.field || '',
            color: config.labels?.color || '#222222',
        },
        heatmap: {
            blur: config.heatmap?.blur ?? 15,
            radius: config.heatmap?.radius ?? 8,
            weight: config.heatmap?.weight || '',
        },
        opacity: layer.opacity ?? 1,
        legend: config.legend || [],
    };
}

function buildStyleConfig(form) {
    const style_config = {
        renderer: form.renderer,
        field: form.field || null,
        colorRamp: form.colorRamp,
        classCount: form.classCount,
        symbol: { ...form.symbol },
        labels: {
            enabled: form.labels.enabled,
            field: form.labels.field || null,
            color: form.labels.color,
        },
        heatmap: {
            blur: Number(form.heatmap.blur),
            radius: Number(form.heatmap.radius),
            weight: form.heatmap.weight || form.field || null,
        },
        uniqueValues: form.uniqueValues,
        classes: form.classes,
        legend: form.legend,
    };

    if (form.renderer === 'graduated' && (!form.classes || !form.classes.length)) {
        style_config.classes = buildEqualIntervalClasses(
            form.classMin,
            form.classMax,
            form.classCount,
            form.colorRamp
        );
        style_config.legend = style_config.classes.map((cls) => ({
            label: cls.label,
            color: cls.color,
        }));
    }

    if (form.renderer === 'unique' && form.uniqueValues?.length) {
        style_config.legend = form.uniqueValues.map((entry) => ({
            label: entry.label || String(entry.value),
            color: entry.color,
            icon: entry.icon || form.symbol.icon,
        }));
    }

    if (form.renderer === 'simple') {
        style_config.legend = [
            {
                label: 'Features',
                color: form.symbol.fillColor,
                icon: form.symbol.icon,
            },
        ];
    }

    return style_config;
}

export default function StyleEditor() {
    const selectedLayerId = useMapStore((state) => state.selectedLayer);
    const layers = useMapStore((state) => state.layers);
    const updateLayerStyle = useMapStore((state) => state.updateLayerStyle);
    const updateLayer = useMapStore((state) => state.updateLayer);

    const selectedLayer = layers.find((layer) => layer.id === selectedLayerId) || null;
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [status, setStatus] = useState(null);
    const [layerFields, setLayerFields] = useState([]);
    const [columnNames, setColumnNames] = useState([]);
    const [fieldValues, setFieldValues] = useState([]);

    useEffect(() => {
        if (!selectedLayer?.id) {
            setLayerFields([]);
            setColumnNames([]);
            return;
        }
        let cancelled = false;
        fetch(`/api/layers/${selectedLayer.id}/fields`, { headers: jsonHeaders() })
            .then((res) => res.json())
            .then((data) => {
                if (!cancelled) {
                    const list = data.fields || data.data || (Array.isArray(data) ? data : []);
                    setLayerFields(list);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLayerFields([]);
                }
            });
        fetch(`/api/layers/${selectedLayer.id}/columns`, { headers: jsonHeaders() })
            .then((res) => res.json())
            .then((data) => {
                if (!cancelled) {
                    setColumnNames(Array.isArray(data.columns) ? data.columns : []);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setColumnNames([]);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [selectedLayer?.id]);

    useEffect(() => {
        if (!selectedLayer?.id || !form.field || form.renderer !== 'unique') {
            setFieldValues([]);
            return;
        }
        let cancelled = false;
        fetch(`/api/layers/${selectedLayer.id}/columns/${encodeURIComponent(form.field)}/values`, {
            headers: jsonHeaders(),
        })
            .then((res) => res.json())
            .then((data) => {
                if (!cancelled) {
                    setFieldValues(Array.isArray(data.values) ? data.values : []);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFieldValues([]);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [selectedLayer?.id, form.field, form.renderer]);

    const attributeFields = useMemo(() => {
        if (columnNames.length) {
            return columnNames;
        }
        const fromRegistry = (Array.isArray(layerFields) ? layerFields : [])
            .map((f) => (typeof f === 'string' ? f : f.name || f.field || f.key))
            .filter(Boolean);
        return [...new Set(fromRegistry)];
    }, [columnNames, layerFields]);

    const toggleUniqueValue = (value) => {
        const key = String(value);
        const existing = form.uniqueValues.find((entry) => String(entry.value) === key);
        if (existing) {
            patchForm({
                uniqueValues: form.uniqueValues.filter((entry) => String(entry.value) !== key),
            });
            return;
        }
        const ramp = COLOR_RAMPS[form.colorRamp] || COLOR_RAMPS.blues;
        patchForm({
            uniqueValues: [
                ...form.uniqueValues,
                {
                    value,
                    label: key,
                    color: ramp[form.uniqueValues.length % ramp.length],
                    icon: form.symbol.icon || 'circle',
                },
            ],
        });
    };

    useEffect(() => {
        if (!selectedLayer) {
            setForm(emptyForm());
            return;
        }
        setForm(formFromLayer(selectedLayer));
        setStatus(null);
    }, [selectedLayer]);

    const patchForm = (partial) => setForm((prev) => ({ ...prev, ...partial }));
    const patchSymbol = (partial) =>
        setForm((prev) => ({ ...prev, symbol: { ...prev.symbol, ...partial } }));
    const patchLabels = (partial) =>
        setForm((prev) => ({ ...prev, labels: { ...prev.labels, ...partial } }));
    const patchHeatmap = (partial) =>
        setForm((prev) => ({ ...prev, heatmap: { ...prev.heatmap, ...partial } }));

    const regenerateClasses = () => {
        const classes = buildEqualIntervalClasses(
            form.classMin,
            form.classMax,
            form.classCount,
            form.colorRamp
        );
        patchForm({ classes, legend: classes.map((c) => ({ label: c.label, color: c.color })) });
    };

    const applyStyle = async () => {
        if (!selectedLayer) {
            return;
        }

        const style_config = buildStyleConfig(form);
        setSaving(true);
        setStatus(null);

        try {
            const response = await fetch(`/layers/${selectedLayer.id}/style`, {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({ style_config }),
            });

            if (!response.ok) {
                const text = await response.text();
                throw new Error(text || `Style save failed (${response.status})`);
            }

            updateLayerStyle(selectedLayer.id, style_config);
            updateLayer(selectedLayer.id, { opacity: form.opacity, style_config });
            setStatus({ type: 'success', message: 'Style applied successfully.' });
        } catch (error) {
            console.error('Failed to save layer style:', error);
            // Still update local store so the map reflects the choice immediately.
            updateLayerStyle(selectedLayer.id, style_config);
            updateLayer(selectedLayer.id, { opacity: form.opacity, style_config });
            setStatus({
                type: 'warning',
                message: `Saved locally; server sync failed (${error.message}). CSRF: ${getCsrfToken() ? 'ok' : 'missing'}`,
            });
        } finally {
            setSaving(false);
        }
    };

    const resetStyle = () => {
        const next = emptyForm();
        next.opacity = selectedLayer?.opacity ?? 1;
        setForm(next);
        if (selectedLayer) {
            const style_config = buildStyleConfig(next);
            updateLayerStyle(selectedLayer.id, style_config);
        }
    };

    if (!selectedLayer) {
        return (
            <div className="style-editor">
                <div className="panel-header">
                    <h5>Style Editor</h5>
                </div>
                <div className="empty-state">
                    <Palette className="glyph lg" strokeWidth={1.5} aria-hidden="true" />
                    <p>Select a layer to edit its style</p>
                </div>
            </div>
        );
    }

    const showVectorControls = selectedLayer.type !== 'wms';
    const geometry = String(selectedLayer.geometry_type || '').toLowerCase();
    const isLineOrPolygon = geometry.includes('line') || geometry.includes('polygon');
    const isRaster = geometry.includes('raster') || selectedLayer.type === 'wms';
    const showPointSymbols = !isLineOrPolygon && !isRaster && form.renderer !== 'heatmap';

    return (
        <div className="style-editor">
            <div className="panel-header">
                <h5>Style Editor</h5>
            </div>

            <div className="style-form">
                <div className="mb-3">
                    <label className="form-label">Layer Name:</label>
                    <input
                        value={selectedLayer.name || ''}
                        type="text"
                        className="form-control form-control-sm"
                        disabled
                        readOnly
                    />
                </div>

                <div className="mb-3">
                    <label className="form-label">Opacity:</label>
                    <input
                        value={form.opacity}
                        type="range"
                        min="0"
                        max="1"
                        step="0.1"
                        className="form-range"
                        onChange={(event) => patchForm({ opacity: Number(event.target.value) })}
                    />
                    <small className="text-muted">{Math.round(form.opacity * 100)}%</small>
                </div>

                {showVectorControls && (
                    <>
                        <div className="mb-3">
                            <label className="form-label">Renderer:</label>
                            <select
                                className="form-select form-select-sm"
                                value={form.renderer}
                                onChange={(event) => patchForm({ renderer: event.target.value })}
                            >
                                {RENDERERS.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {(form.renderer === 'unique' ||
                            form.renderer === 'graduated' ||
                            form.renderer === 'heatmap') && (
                            <div className="mb-3">
                                <label className="form-label">Classification field:</label>
                                <select
                                    className="form-select form-select-sm"
                                    value={form.field}
                                    onChange={(event) =>
                                        patchForm({ field: event.target.value, uniqueValues: [] })
                                    }
                                >
                                    <option value="">— select —</option>
                                    {attributeFields.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        {(form.renderer === 'unique' || form.renderer === 'graduated') && (
                            <div className="mb-3">
                                <label className="form-label">Color ramp:</label>
                                <select
                                    className="form-select form-select-sm"
                                    value={form.colorRamp}
                                    onChange={(event) => patchForm({ colorRamp: event.target.value })}
                                >
                                    {Object.keys(COLOR_RAMPS).map((key) => (
                                        <option key={key} value={key}>
                                            {key}
                                        </option>
                                    ))}
                                </select>
                                <div className="d-flex gap-1 mt-1">
                                    {(COLOR_RAMPS[form.colorRamp] || []).map((color) => (
                                        <span
                                            key={color}
                                            style={{
                                                flex: 1,
                                                height: 8,
                                                backgroundColor: color,
                                                borderRadius: 2,
                                            }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}

                        {form.renderer === 'graduated' && (
                            <div className="border rounded p-2 mb-3">
                                <div className="row g-2 mb-2">
                                    <div className="col-4">
                                        <label className="form-label small">Classes</label>
                                        <input
                                            type="number"
                                            min="2"
                                            max="12"
                                            className="form-control form-control-sm"
                                            value={form.classCount}
                                            onChange={(event) =>
                                                patchForm({ classCount: Number(event.target.value) })
                                            }
                                        />
                                    </div>
                                    <div className="col-4">
                                        <label className="form-label small">Min</label>
                                        <input
                                            type="number"
                                            className="form-control form-control-sm"
                                            value={form.classMin}
                                            onChange={(event) =>
                                                patchForm({ classMin: Number(event.target.value) })
                                            }
                                        />
                                    </div>
                                    <div className="col-4">
                                        <label className="form-label small">Max</label>
                                        <input
                                            type="number"
                                            className="form-control form-control-sm"
                                            value={form.classMax}
                                            onChange={(event) =>
                                                patchForm({ classMax: Number(event.target.value) })
                                            }
                                        />
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm w-100"
                                    onClick={regenerateClasses}
                                >
                                    <RefreshCw className="glyph" strokeWidth={1.75} aria-hidden="true" /> Generate classes
                                </button>
                                {form.classes?.length > 0 && (
                                    <ul className="list-unstyled small mt-2 mb-0">
                                        {form.classes.map((cls, index) => (
                                            <li key={index} className="d-flex align-items-center gap-2 mb-1">
                                                <span
                                                    style={{
                                                        width: 12,
                                                        height: 12,
                                                        background: cls.color,
                                                        border: '1px solid #ccc',
                                                    }}
                                                />
                                                {cls.label}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        {form.renderer === 'unique' && form.field && (
                            <div className="mb-3">
                                <label className="form-label">Values</label>
                                {fieldValues.length ? (
                                    <div className="border rounded p-2" style={{ maxHeight: 180, overflowY: 'auto' }}>
                                        {fieldValues.map((value) => {
                                            const key = String(value);
                                            const checked = form.uniqueValues.some(
                                                (entry) => String(entry.value) === key
                                            );
                                            return (
                                                <label key={key} className="d-flex align-items-center gap-2 small mb-1">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleUniqueValue(value)}
                                                    />
                                                    <span className="text-truncate">{key}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <p className="small text-muted mb-0">No values in this field.</p>
                                )}
                            </div>
                        )}

                        {form.renderer === 'heatmap' && (
                            <div className="border rounded p-2 mb-3">
                                <div className="mb-2">
                                    <label className="form-label small">Blur</label>
                                    <input
                                        type="range"
                                        min="1"
                                        max="40"
                                        className="form-range"
                                        value={form.heatmap.blur}
                                        onChange={(event) =>
                                            patchHeatmap({ blur: Number(event.target.value) })
                                        }
                                    />
                                </div>
                                <div className="mb-2">
                                    <label className="form-label small">Radius</label>
                                    <input
                                        type="range"
                                        min="1"
                                        max="30"
                                        className="form-range"
                                        value={form.heatmap.radius}
                                        onChange={(event) =>
                                            patchHeatmap({ radius: Number(event.target.value) })
                                        }
                                    />
                                </div>
                                <div>
                                    <label className="form-label small">Weight field (optional)</label>
                                    <input
                                        type="text"
                                        className="form-control form-control-sm"
                                        value={form.heatmap.weight}
                                        onChange={(event) =>
                                            patchHeatmap({ weight: event.target.value })
                                        }
                                    />
                                </div>
                            </div>
                        )}

                        {showPointSymbols && (
                            <div className="mb-3">
                                <label className="form-label">Point symbol</label>
                                <div className="d-flex flex-wrap gap-1">
                                    {POINT_ICONS.map((icon) => {
                                        const selected = (form.symbol.icon || 'circle') === icon.id;
                                        const Glyph = icon.Icon;
                                        return (
                                            <button
                                                key={icon.id}
                                                type="button"
                                                className={`btn btn-sm ${selected ? 'btn-dark' : 'btn-outline-secondary'}`}
                                                title={icon.label}
                                                aria-label={icon.label}
                                                aria-pressed={selected}
                                                onClick={() => patchSymbol({ icon: icon.id })}
                                            >
                                                {Glyph ? (
                                                    <Glyph size={16} stroke={1.75} />
                                                ) : (
                                                    <span
                                                        aria-hidden="true"
                                                        style={{
                                                            display: 'inline-block',
                                                            width: 10,
                                                            height: 10,
                                                            borderRadius: '50%',
                                                            background: form.symbol.fillColor,
                                                        }}
                                                    />
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                                {form.symbol.icon && form.symbol.icon !== 'circle' && (
                                    <div className="mt-2">
                                        <label className="form-label small">
                                            Icon size: {form.symbol.size || 24}px
                                        </label>
                                        <input
                                            type="range"
                                            min="12"
                                            max="48"
                                            className="form-range"
                                            value={form.symbol.size || 24}
                                            onChange={(event) =>
                                                patchSymbol({ size: Number(event.target.value) })
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        )}

                        {form.renderer === 'unique' && showPointSymbols && form.uniqueValues.length > 0 && (
                            <div className="mb-3">
                                {form.uniqueValues.map((entry, index) => (
                                    <div key={entry.value} className="d-flex align-items-center gap-2 mb-1">
                                        <span className="small text-truncate" style={{ width: 72 }}>
                                            {entry.value}
                                        </span>
                                        <select
                                            className="form-select form-select-sm"
                                            value={entry.icon || form.symbol.icon || 'circle'}
                                            onChange={(event) => {
                                                const uniqueValues = form.uniqueValues.map((item, itemIndex) =>
                                                    itemIndex === index
                                                        ? { ...item, icon: event.target.value }
                                                        : item
                                                );
                                                patchForm({ uniqueValues });
                                            }}
                                        >
                                            {POINT_ICONS.map((icon) => (
                                                <option key={icon.id} value={icon.id}>
                                                    {icon.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                ))}
                            </div>
                        )}

                        {form.renderer === 'simple' && (
                            <div className="vector-styles">
                                <h6>Symbol</h6>
                                <div className="mb-3">
                                    <label className="form-label">Fill Color:</label>
                                    <input
                                        value={form.symbol.fillColor}
                                        type="color"
                                        className="form-control form-control-color"
                                        onChange={(event) =>
                                            patchSymbol({ fillColor: event.target.value })
                                        }
                                    />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">Fill Opacity:</label>
                                    <input
                                        value={form.symbol.fillOpacity}
                                        type="range"
                                        min="0"
                                        max="1"
                                        step="0.1"
                                        className="form-range"
                                        onChange={(event) =>
                                            patchSymbol({
                                                fillOpacity: Number(event.target.value),
                                            })
                                        }
                                    />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">Stroke Color:</label>
                                    <input
                                        value={form.symbol.strokeColor}
                                        type="color"
                                        className="form-control form-control-color"
                                        onChange={(event) =>
                                            patchSymbol({ strokeColor: event.target.value })
                                        }
                                    />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">Stroke Width:</label>
                                    <input
                                        value={form.symbol.strokeWidth}
                                        type="number"
                                        min="1"
                                        max="10"
                                        className="form-control form-control-sm"
                                        onChange={(event) =>
                                            patchSymbol({
                                                strokeWidth: Number(event.target.value),
                                            })
                                        }
                                    />
                                </div>
                            </div>
                        )}

                        <div className="form-check form-switch mb-2">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                id="labelsEnabled"
                                checked={form.labels.enabled}
                                onChange={(event) =>
                                    patchLabels({ enabled: event.target.checked })
                                }
                            />
                            <label className="form-check-label" htmlFor="labelsEnabled">
                                Show labels
                            </label>
                        </div>

                        {form.labels.enabled && (
                            <div className="mb-3">
                                <label className="form-label">Label field:</label>
                                {attributeFields.length ? (
                                    <select
                                        className="form-select form-select-sm"
                                        value={form.labels.field}
                                        onChange={(event) =>
                                            patchLabels({ field: event.target.value })
                                        }
                                    >
                                        <option value="">— select —</option>
                                        {attributeFields.map((name) => (
                                            <option key={name} value={name}>
                                                {name}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <input
                                        type="text"
                                        className="form-control form-control-sm"
                                        value={form.labels.field}
                                        onChange={(event) =>
                                            patchLabels({ field: event.target.value })
                                        }
                                    />
                                )}
                            </div>
                        )}
                    </>
                )}

                {status && (
                    <div
                        className={`alert alert-${status.type === 'success' ? 'success' : 'warning'} py-1 px-2 small`}
                    >
                        {status.message}
                    </div>
                )}

                <div className="mb-3">
                    <button
                        type="button"
                        onClick={applyStyle}
                        className="btn btn-primary btn-sm w-100"
                        disabled={saving}
                    >
                        {saving ? (
                            <Loader2 className="glyph spin" strokeWidth={1.75} aria-hidden="true" />
                        ) : (
                            <Check className="glyph" strokeWidth={1.75} aria-hidden="true" />
                        )}{' '}
                        Apply Style
                    </button>
                </div>

                <div className="mb-3">
                    <button type="button" onClick={resetStyle} className="btn btn-secondary btn-sm w-100">
                        <Undo2 className="glyph" strokeWidth={1.75} aria-hidden="true" /> Reset to Default
                    </button>
                </div>
            </div>
        </div>
    );
}
