import { fieldLabel } from './widgetDocument';
import { Field, Select, TextArea, TextInput } from '@/components/gis';

export default function Inspector({ widget, layers = [], maps = [], onChange, onClose }) {
    if (!widget) {
        return (
            <aside className="flex w-80 shrink-0 flex-col border-l border-border bg-card">
                <div className="border-b border-border px-4 py-3 font-semibold">Inspector</div>
                <p className="px-4 py-6 text-sm text-muted-foreground">Select a widget on the canvas to configure it.</p>
            </aside>
        );
    }

    const layer = layers.find((item) => item.id === widget.layer_id) || null;
    const fields = layer?.fields || [];
    const mapSource = widget.type === 'map' && widget.source === 'map' ? 'map' : 'layer';
    const showLayer = widget.type !== 'text' && !(widget.type === 'map' && mapSource === 'map');

    function patch(partial) {
        onChange({ ...widget, ...partial });
    }

    return (
        <aside className="flex w-80 shrink-0 flex-col border-l border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <p className="font-semibold">Inspector</p>
                <button type="button" className="text-sm text-muted-foreground hover:text-foreground" onClick={onClose}>
                    Done
                </button>
            </div>
            <div className="space-y-4 overflow-y-auto px-4 py-4">
                <Field label="Title">
                    <TextInput value={widget.title || ''} onChange={(event) => patch({ title: event.target.value })} />
                </Field>

                {widget.type === 'map' ? (
                    <Field label="Source" hint="Choose a layer or a saved map when this widget should show a map.">
                        <Select
                            value={mapSource}
                            onChange={(event) => {
                                const next = event.target.value === 'map' ? 'map' : 'layer';
                                patch(next === 'map' ? { source: 'map', layer_id: null } : { source: 'layer', map_id: null });
                            }}
                        >
                            <option value="layer">Layer</option>
                            <option value="map">Saved map</option>
                        </Select>
                    </Field>
                ) : null}

                {widget.type === 'map' && mapSource === 'map' ? (
                    <Field label="Saved map" hint="The shared view includes this map when one is selected.">
                        <Select
                            value={widget.map_id || ''}
                            onChange={(event) => patch({ map_id: event.target.value ? Number(event.target.value) : null, source: 'map' })}
                        >
                            <option value="">No saved map</option>
                            {maps.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                ) : null}

                {showLayer ? (
                    <Field label="Layer" hint="Choose a layer when this widget should show data.">
                        <Select
                            value={widget.layer_id || ''}
                            onChange={(event) => patch({ layer_id: event.target.value ? Number(event.target.value) : null })}
                        >
                            <option value="">No layer</option>
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
                        <Field label="Aggregation">
                            <Select value={widget.aggregation || 'count'} onChange={(event) => patch({ aggregation: event.target.value })}>
                                <option value="count">Count</option>
                                <option value="sum">Sum</option>
                                <option value="avg">Average</option>
                            </Select>
                        </Field>
                        {(widget.aggregation === 'sum' || widget.aggregation === 'avg') && (
                            <Field label="Column">
                                <Select value={widget.column || ''} onChange={(event) => patch({ column: event.target.value || null })}>
                                    <option value="">Choose a column</option>
                                    {fields.map((field) => (
                                        <option key={field.name} value={field.name}>
                                            {fieldLabel(field)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                        <Field label="Prefix">
                            <TextInput value={widget.prefix || ''} onChange={(event) => patch({ prefix: event.target.value })} />
                        </Field>
                        <Field label="Suffix">
                            <TextInput value={widget.suffix || ''} onChange={(event) => patch({ suffix: event.target.value })} />
                        </Field>
                    </>
                ) : null}

                {widget.type === 'serial' || widget.type === 'pie' ? (
                    <>
                        {widget.type === 'serial' ? (
                            <Field label="Chart style">
                                <Select value={widget.chart_style || 'bar'} onChange={(event) => patch({ chart_style: event.target.value })}>
                                    <option value="bar">Bar</option>
                                    <option value="line">Line</option>
                                </Select>
                            </Field>
                        ) : null}
                        <Field label="Group by">
                            <Select value={widget.group_by || ''} onChange={(event) => patch({ group_by: event.target.value || null })}>
                                <option value="">Choose a field</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Aggregation">
                            <Select value={widget.aggregation || 'count'} onChange={(event) => patch({ aggregation: event.target.value })}>
                                <option value="count">Count</option>
                                <option value="sum">Sum</option>
                                <option value="avg">Average</option>
                            </Select>
                        </Field>
                        {(widget.aggregation === 'sum' || widget.aggregation === 'avg') && (
                            <Field label="Value column">
                                <Select value={widget.column || ''} onChange={(event) => patch({ column: event.target.value || null })}>
                                    <option value="">Choose a column</option>
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
                        <Field label="Columns" hint="Leave empty to show all attribute columns.">
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
                        <Field label="Row limit">
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
                        <Field label="Title field">
                            <Select value={widget.title_field || ''} onChange={(event) => patch({ title_field: event.target.value || null })}>
                                <option value="">Choose a field</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Description field">
                            <Select
                                value={widget.description_field || ''}
                                onChange={(event) => patch({ description_field: event.target.value || null })}
                            >
                                <option value="">None</option>
                                {fields.map((field) => (
                                    <option key={field.name} value={field.name}>
                                        {fieldLabel(field)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Row limit">
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

                {widget.type === 'map' && mapSource === 'layer' ? (
                    <Field label="Basemap">
                        <Select value={widget.basemap || 'osm'} onChange={(event) => patch({ basemap: event.target.value })}>
                            <option value="osm">Street</option>
                            <option value="imagery">Satellite</option>
                        </Select>
                    </Field>
                ) : null}

                {widget.type === 'text' ? (
                    <Field label="Body">
                        <TextArea value={widget.body || ''} onChange={(event) => patch({ body: event.target.value })} />
                    </Field>
                ) : null}

                {widget.type === 'category' ? (
                    <Field label="Field">
                        <Select
                            value={widget.group_by || widget.column || ''}
                            onChange={(event) => patch({ group_by: event.target.value || null, column: event.target.value || null })}
                        >
                            <option value="">Choose a field</option>
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
