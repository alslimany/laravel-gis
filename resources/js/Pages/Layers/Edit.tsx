import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, Panel, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';

type ProjectOption = { id: number; name: string };

type LayerRecord = {
    id: number;
    name: string;
    description?: string | null;
    project_id?: number | string | null;
    table_name?: string | null;
    geometry_type?: string | null;
    style_config?: Record<string, string | number> | null;
};

function withAlpha(hex: string, alpha: number) {
    const raw = hex.replace('#', '');
    if (!/^[0-9a-fA-F]{6}$/.test(raw)) {
        return hex;
    }
    const red = Number.parseInt(raw.slice(0, 2), 16);
    const green = Number.parseInt(raw.slice(2, 4), 16);
    const blue = Number.parseInt(raw.slice(4, 6), 16);
    return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
}

function ColorControl({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field label={label}>
            <label className="flex h-9 items-center gap-2 rounded-md border border-input px-2 shadow-xs">
                <input
                    type="color"
                    value={value}
                    aria-label={label}
                    className="size-6 cursor-pointer border-0 bg-transparent p-0"
                    onChange={(event) => onChange(event.target.value)}
                />
                <span className="text-sm tabular-nums">{value}</span>
            </label>
        </Field>
    );
}

export default function Edit({ layer, projects = [] }: { layer: LayerRecord; projects?: ProjectOption[] }) {
    const styleConfig = layer.style_config || {};
    const details = useForm({
        name: layer.name || '',
        description: layer.description || '',
        project_id: layer.project_id ? String(layer.project_id) : '',
    });
    const [fillColor, setFillColor] = useState(String(styleConfig.fill_color || styleConfig.fillColor || '#06b6d4'));
    const [strokeColor, setStrokeColor] = useState(String(styleConfig.stroke_color || styleConfig.strokeColor || '#dae2fd'));
    const [strokeWidth, setStrokeWidth] = useState(String(styleConfig.stroke_width || styleConfig.strokeWidth || 1));
    const [fillOpacity, setFillOpacity] = useState(String(styleConfig.fill_opacity || styleConfig.fillOpacity || 0.4));
    const [savingStyle, setSavingStyle] = useState(false);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        details.put(`/layers/${layer.id}`);
    }

    function saveStyle(event: React.FormEvent) {
        event.preventDefault();
        router.post(
            `/layers/${layer.id}/style`,
            {
                style_config: {
                    fill_color: fillColor,
                    stroke_color: strokeColor,
                    stroke_width: strokeWidth,
                    fill_opacity: fillOpacity,
                },
            },
            {
                onStart: () => setSavingStyle(true),
                onFinish: () => setSavingStyle(false),
            },
        );
    }

    const opacity = Math.min(1, Math.max(0, Number(fillOpacity) || 0));

    return (
        <AppLayout title={`Edit ${layer.name}`}>
            <PageHeader title={`Edit ${layer.name}`} action={<GhostLink href={`/layers/${layer.id}`}>Back</GhostLink>} />
            <Panel className="max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Layer name" error={details.errors.name}>
                        <TextInput value={details.data.name} required onChange={(event) => details.setData('name', event.target.value)} />
                    </Field>
                    <Field label="Description" error={details.errors.description}>
                        <TextArea value={details.data.description} onChange={(event) => details.setData('description', event.target.value)} />
                    </Field>
                    <Field label="Project" error={details.errors.project_id}>
                        <Select value={details.data.project_id} onChange={(event) => details.setData('project_id', event.target.value)}>
                            <option value="">No project</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <dl className="grid gap-3 border-t pt-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-muted-foreground">Table</dt>
                            <dd className="mt-0.5 tabular-nums">{layer.table_name || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Geometry</dt>
                            <dd className="mt-0.5">{layer.geometry_type || 'Not detected'}</dd>
                        </div>
                    </dl>
                    <div className="flex gap-3">
                        <GhostLink href={`/layers/${layer.id}`}>Cancel</GhostLink>
                        <PrimaryButton type="submit" disabled={details.processing}>
                            {details.processing ? 'Saving…' : 'Save layer'}
                        </PrimaryButton>
                    </div>
                </form>
            </Panel>
            <Panel className="max-w-xl">
                <form onSubmit={saveStyle} className="space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">Style</h2>
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">
                            Fill and stroke are written to this layer. A published layer also updates its GeoServer style.
                        </p>
                    </div>
                    <div
                        className="h-16 rounded-md border bg-muted"
                        style={{
                            backgroundColor: withAlpha(fillColor, opacity),
                            borderColor: strokeColor,
                            borderWidth: `${Math.min(10, Math.max(1, Number(strokeWidth) || 1))}px`,
                        }}
                        aria-hidden="true"
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ColorControl label="Fill" value={fillColor} onChange={setFillColor} />
                        <ColorControl label="Stroke" value={strokeColor} onChange={setStrokeColor} />
                        <Field label="Stroke width">
                            <TextInput type="number" min="1" max="10" value={strokeWidth} onChange={(event) => setStrokeWidth(event.target.value)} />
                        </Field>
                        <Field label="Fill opacity">
                            <TextInput type="number" min="0" max="1" step="0.1" value={fillOpacity} onChange={(event) => setFillOpacity(event.target.value)} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" disabled={savingStyle}>
                        {savingStyle ? 'Saving style…' : 'Save style'}
                    </PrimaryButton>
                </form>
            </Panel>
        </AppLayout>
    );
}
