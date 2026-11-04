import { fieldLabel } from './widgetDocument';
import { Field, Select, TextArea, TextInput } from '@/components/gis';

import { __ } from '@/lib/i18n';
const ANALYSIS_TYPES = ['indicator', 'serial', 'pie', 'table', 'list'];

export default function Inspector({ widget, layers = [], maps = [], analyses = [], liveView = null, onChange, onClose }) {
    if (!widget) {
        return (
            <aside className="flex w-80 shrink-0 flex-col border-s border-border bg-card">
                <div className="border-b border-border px-4 py-3 font-semibold">{__('dash.inspector')}</div>
                <p className="px-4 py-6 text-sm text-muted-foreground">{__('dash.select_widget')}</p>
            </aside>
        );
    }

    const mapSource = widget.type === 'map' && widget.source === 'map' ? 'map' : 'layer';
    const supportsAnalysis = ANALYSIS_TYPES.includes(widget.type);
    const dataSource = supportsAnalysis && widget.source === 'analysis' ? 'analysis' : 'layer';
    const analysis = analyses.find((item) => item.id === widget.analysis_id) || null;
    const layer =
        (dataSource === 'analysis'
            ? layers.find((item) => item.id === analysis?.layer_id)
            : layers.find((item) => item.id === widget.layer_id)) || null;
    const fields = layer?.fields || [];
    const showLayer = widget.type !== 'text' && !(widget.type === 'map' && mapSource === 'map') && dataSource !== 'analysis';

    function patch(partial) {
        onChange({ ...widget, ...partial });
    }

    function viewportSummary(viewport) {
        if (!viewport) return __('dash.view_auto');
        if (viewport.bounds) {
            return __('dash.view_bounds', { bounds: viewport.bounds.map((n) => Number(n).toFixed(4)).join(', '), zoom: Math.round(viewport.zoom ?? 0) });
        }
        if (viewport.center) {
            return __('dash.view_center', { zoom: Math.round(viewport.zoom ?? 0), lon: Number(viewport.center[0]).toFixed(5), lat: Number(viewport.center[1]).toFixed(5) });
        }
        return __('dash.view_auto');
    }

    function captureView() {
        if (!liveView || liveView.widgetId !== widget.id || !liveView.center) return;
        patch({
            viewport: {
                center: [
                    Number(Number(liveView.center[0]).toFixed(6)),
                    Number(Number(liveView.center[1]).toFixed(6)),
                ],
                zoom: Number(Number(liveView.zoom ?? 12).toFixed(2)),
                rotation: Number(Number(liveView.rotation ?? 0).toFixed(4)),
            },
        });
    }

    function patchViewportNumber(key, raw) {
        if (raw === '') return;
        const value = Number(raw);
        if (!Number.isFinite(value)) return;

        // Typing a view replaces any bounds: one saved rectangle or one point of view.
        const { bounds, ...rest } = widget.viewport || {};
        if (key === 'zoom') {
            patch({ viewport: { ...rest, zoom: Math.max(0, Math.min(22, value)) } });
            return;
        }
        const center = Array.isArray(rest.center) ? [...rest.center] : [0, 0];
        center[key === 'lon' ? 0 : 1] = value;
        patch({ viewport: { ...rest, center } });
    }

    const canCapture = Boolean(liveView && liveView.widgetId === widget.id && liveView.center);

    return (
        <aside className="flex w-80 shrink-0 flex-col border-s border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <p className="font-semibold">{__('dash.inspector')}</p>
                <button type="button" className="text-sm text-muted-foreground hover:text-foreground" onClick={onClose}>
                    {__('dash.done')}
                </button>
            </div>
            <div className="space-y-4 overflow-y-auto px-4 py-4">
                <Field label={__('dash.title')}>
                    <TextInput value={widget.title || ''} onChange={(event) => patch({ title: event.target.value })} />
                </Field>

                {supportsAnalysis ? (
                    <Field label={__('dash.source')} hint={__('dash.hint_data_source')}>
                        <Select
                            value={dataSource}
                            onChange={(event) => {
                                const next = event.target.value === 'analysis' ? 'analysis' : 'layer';
                                patch(next === 'analysis' ? { source: 'analysis' } : { source: 'layer' });
                            }}
                        >
                            <option value="layer">{__('dash.layer')}</option>
                            <option value="analysis">{__('dash.saved_analysis')}</option>
                        </Select>
                    </Field>
                ) : null}

                {supportsAnalysis && dataSource === 'analysis' ? (
                    <Field label={__('dash.saved_analysis')} hint={__('dash.hint_analysis')}>
                        <Select
                            value={widget.analysis_id || ''}
                            onChange={(event) =>
                                patch({
                                    analysis_id: event.target.value ? Number(event.target.value) : null,
                                    source: 'analysis',
                                })
                            }
                        >
                            <option value="">{__('dash.no_analysis')}</option>
                            {analyses.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                    {item.layer_name ? ` · ${item.layer_name}` : ''}
                                    {typeof item.feature_count === 'number' ? ` · ${item.feature_count}` : ''}
                                </option>
                            ))}
                        </Select>
                        {analysis?.resulting_layer_id ? (
                            <a
                                href={`/layers/${analysis.resulting_layer_id}`}
                                className="mt-1 inline-block text-xs font-medium text-primary hover:underline"
                            >
                                {__('dash.open_result_layer')}
                            </a>
                        ) : null}
                    </Field>
                ) : null}

                {widget.type === 'map' ? (
                    <Field label={__('dash.source')} hint={__('dash.hint_map_source')}>
                        <Select
                            value={mapSource}
                            onChange={(event) => {
                                const next = event.target.value === 'map' ? 'map' : 'layer';
                                patch(next === 'map' ? { source: 'map', layer_id: null } : { source: 'layer', map_id: null });
                            }}
                        >
                            <option value="layer">{__('dash.layer')}</option>
                            <option value="map">{__('dash.saved_map')}</option>
                        </Select>
                    </Field>
                ) : null}

                {widget.type === 'map' && mapSource === 'map' ? (
                    <Field label={__('dash.saved_map')} hint={__('dash.hint_public_map')}>
                        <Select
                            value={widget.map_id || ''}
                            onChange={(event) => patch({ map_id: event.target.value ? Number(event.target.value) : null, source: 'map' })}
                        >
                            <option value="">{__('dash.no_map')}</option>
                            {maps.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}{item.is_public ? '' : ' · Private'}
                                </option>
                            ))}
                        </Select>
                    </Field>
                ) : null}

                {showLayer ? (
                    <Field label={__('dash.layer')} hint={__('dash.hint_layer')}>
                        <Select
                            value={widget.layer_id || ''}
                            onChange={(event) => patch({ layer_id: event.target.value ? Number(event.target.value) : null })}
                        >
                            <option value="">{__('dash.no_layer')}</option>
                            {layers.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                    {item.published ? '' : ' (draft)'}
                                </option>
                            ))}
                        </Select>
                    </Field>
                ) : null}

                {widget.type === 'indicator' ? (
                    <>
                        <Field label={__('dash.aggregation')}>
                            <Select value={widget.aggregation || 'count'} onChange={(event) => patch({ aggregation: event.target.value })}>
                                <option value="count">{__('dash.agg_count')}</option>
                                <option value="sum">{__('dash.agg_sum')}</option>
                                <option value="avg">{__('dash.agg_avg')}</option>
                            </Select>
                        </Field>
                        {(widget.aggregation === 'sum' || widget.aggregation === 'avg') && (
                            <Field label={__('dash.column')}>
                                <Select value={widget.column || ''} onChange={(event) => patch({ column: event.target.value || null })}>
                                    <option value="">{__('dash.choose_column')}</option>
                                    {fields.map((field) => (
                                        <option key={field.name} value={field.name}>
                                            {fieldLabel(field)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                        <Field label={__('dash.prefix')}>
                            <TextInput value={widget.prefix || ''} onChange={(event) => patch({ prefix: event.target.value })} />
                        </Field>
                        <Field label={__('dash.suffix')}>
                            <TextInput value={widget.suffix || ''} onChange={(event) => patch({ suffix: event.target.value })} />
                        </Field>
                    </>
                ) : null}

                {widget.type === 'serial' || widget.type === 'pie' ? (
                    <>
                        {widget.type === 'serial' ? (
                            <Field label={__('dash.chart_style')}>
                                <Select value={widget.chart_style || 'bar'} onChange={(event) => patch({ chart_style: event.target.value })}>
                                    <option value="bar">{__('dash.bar')}</option>
                                    <option value="line">{__('dash.line')}</option>
                                </Select>
                            </Field>
                        ) : null}
                        <Field label={__('dash.group_by')}>
                            <Select value={widget.group_by || ''} onChange={(event) => patch({ group_by: event.target.value || null })}>
                                <option value="">{__('dash.choose_field')}</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={__('dash.aggregation')}>
                            <Select value={widget.aggregation || 'count'} onChange={(event) => patch({ aggregation: event.target.value })}>
                                <option value="count">{__('dash.agg_count')}</option>
                                <option value="sum">{__('dash.agg_sum')}</option>
                                <option value="avg">{__('dash.agg_avg')}</option>
                            </Select>
                        </Field>
                        {(widget.aggregation === 'sum' || widget.aggregation === 'avg') && (
                            <Field label={__('dash.value_column')}>
                                <Select value={widget.column || ''} onChange={(event) => patch({ column: event.target.value || null })}>
                                    <option value="">{__('dash.choose_column')}</option>
                                    {fields.map((field) => (
                                        <option key={field.name} value={field.name}>
                                            {fieldLabel(field)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                    </>
                ) : null}

                {widget.type === 'table' ? (
                    <>
                        <Field label={__('dash.columns')} hint={__('dash.hint_columns')}>
                            <Select
                                multiple
                                className="min-h-32"
                                value={widget.columns || []}
                                onChange={(event) =>
                                    patch({
                                        columns: Array.from(event.target.selectedOptions).map((option) => option.value),
                                    })
                                }
                            >
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={__('dash.row_limit')}>
                            <TextInput
                                type="number"
                                min="1"
                                max="100"
                                value={widget.limit || 20}
                                onChange={(event) => patch({ limit: Number(event.target.value) || 20 })}
                            />
                        </Field>
                    </>
                ) : null}

                {widget.type === 'list' ? (
                    <>
                        <Field label={__('dash.title_field')}>
                            <Select value={widget.title_field || ''} onChange={(event) => patch({ title_field: event.target.value || null })}>
                                <option value="">{__('dash.choose_field')}</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={__('dash.description_field')}>
                            <Select
                                value={widget.description_field || ''}
                                onChange={(event) => patch({ description_field: event.target.value || null })}
                            >
                                <option value="">{__('dash.none')}</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={__('dash.row_limit')}>
                            <TextInput
                                type="number"
                                min="1"
                                max="100"
                                value={widget.limit || 20}
                                onChange={(event) => patch({ limit: Number(event.target.value) || 20 })}
                            />
                        </Field>
                    </>
                ) : null}

                {widget.type === 'map' ? (
                    <Field
                        label={__('dash.initial_view')}
                        hint={__('dash.hint_initial_view')}
                    >
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">{viewportSummary(widget.viewport)}</p>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    disabled={!canCapture}
                                    onClick={captureView}
                                    className="rounded-md border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {__('dash.use_current_view')}
                                </button>
                                <button
                                    type="button"
                                    disabled={!widget.viewport}
                                    onClick={() => patch({ viewport: null })}
                                    className="rounded-md border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {__('dash.back_to_auto')}
                                </button>
                            </div>
                            <div className="grid grid-cols-3 gap-2">
                                <label className="grid gap-1 text-xs text-muted-foreground">
                                    {__('dash.zoom')}
                                    <input
                                        type="number"
                                        min="0"
                                        max="22"
                                        step="1"
                                        className="h-8 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground"
                                        value={widget.viewport?.zoom ?? ''}
                                        onChange={(event) => patchViewportNumber('zoom', event.target.value)}
                                    />
                                </label>
                                <label className="grid gap-1 text-xs text-muted-foreground">
                                    {__('dash.longitude')}
                                    <input
                                        type="number"
                                        min="-180"
                                        max="180"
                                        step="any"
                                        className="h-8 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground"
                                        value={widget.viewport?.center?.[0] ?? ''}
                                        onChange={(event) => patchViewportNumber('lon', event.target.value)}
                                    />
                                </label>
                                <label className="grid gap-1 text-xs text-muted-foreground">
                                    {__('dash.latitude')}
                                    <input
                                        type="number"
                                        min="-90"
                                        max="90"
                                        step="any"
                                        className="h-8 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground"
                                        value={widget.viewport?.center?.[1] ?? ''}
                                        onChange={(event) => patchViewportNumber('lat', event.target.value)}
                                    />
                                </label>
                            </div>
                        </div>
                    </Field>
                ) : null}

                {widget.type === 'map' && mapSource === 'layer' ? (
                    <Field label={__('dash.basemap')}>
                        <Select value={widget.basemap || 'osm'} onChange={(event) => patch({ basemap: event.target.value })}>
                            <option value="osm">{__('maps.basemap.osm')}</option>
                            <option value="imagery">{__('dash.satellite')}</option>
                        </Select>
                    </Field>
                ) : null}

                {widget.type === 'text' ? (
                    <Field label={__('dash.body')}>
                        <TextArea value={widget.body || ''} onChange={(event) => patch({ body: event.target.value })} />
                    </Field>
                ) : null}

                {widget.type === 'category' ? (
                    <Field label={__('dash.field')}>
                        <Select
                            value={widget.group_by || widget.column || ''}
                            onChange={(event) => patch({ group_by: event.target.value || null, column: event.target.value || null })}
                        >
                            <option value="">{__('dash.choose_field')}</option>
                            {fields.map((field) => (
                                <option key={field.name} value={field.name}>
                                    {fieldLabel(field)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                ) : null}
            </div>
        </aside>
    );
}
