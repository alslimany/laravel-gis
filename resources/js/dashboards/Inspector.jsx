import { fieldLabel } from './widgetDocument';
import { Field, Select, TextArea, TextInput } from '@/components/gis';

export default function Inspector({ widget, layers = [], onChange, onClose }) {
    if (!widget) {
        return (
            <aside className="flex w-80 shrink-0 flex-col border-l border-line bg-panel">
                <div className="border-b border-line px-4 py-3 font-semibold">Inspector</div>
                <p className="px-4 py-6 text-muted">Select a widget on the canvas to configure it.</p>
            </aside>
        );
    }

    const layer = layers.find((item) => item.id === widget.layer_id) || null;
    const fields = layer?.fields || [];
    const needsLayer = widget.type !== 'text';

    function patch(partial) {
        onChange({ ...widget, ...partial });
    }

    return (
        <aside className="flex w-80 shrink-0 flex-col border-l border-line bg-panel">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <p className="font-semibold">Inspector</p>
                <button type="button" className="text-muted hover:text-copy" onClick={onClose}>
                    Done
                </button>
            </div>
            <div className="space-y-4 overflow-y-auto px-4 py-4">
                <Field label="Title">
                    <TextInput value={widget.title || ''} onChange={(event) => patch({ title: event.target.value })} />
                </Field>

                {needsLayer ? (
                    <Field label="Layer">
                        <Select
                            value={widget.layer_id || ''}
                            onChange={(event) => patch({ layer_id: event.target.value ? Number(event.target.value) : null })}
                        >
                            <option value="">Choose a layer</option>
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

                {widget.type === 'map' ? (
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
