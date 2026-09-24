import { router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Field, GhostLink, Heading, PageHeader, PrimaryButton, Select, TextArea, TextInput } from '../../Components/ui';

export default function Edit({ layer, projects = [] }) {
    const form = useForm({
        name: layer.name || '',
        description: layer.description || '',
        project_id: layer.project_id || '',
        fill_color: layer.style_config?.fill_color || layer.style_config?.fillColor || '#06b6d4',
        stroke_color: layer.style_config?.stroke_color || layer.style_config?.strokeColor || '#dae2fd',
        stroke_width: layer.style_config?.stroke_width || layer.style_config?.strokeWidth || 1,
        fill_opacity: layer.style_config?.fill_opacity || layer.style_config?.fillOpacity || 0.4,
    });
    function submit(event) {
        event.preventDefault();
        form.put(`/layers/${layer.id}`);
    }

    function saveStyle(event) {
        event.preventDefault();
        router.post(`/layers/${layer.id}/style`, {
            style_config: {
                fill_color: form.data.fill_color,
                stroke_color: form.data.stroke_color,
                stroke_width: form.data.stroke_width,
                fill_opacity: form.data.fill_opacity,
            },
        });
    }

    return (
        <AppLayout title={`Edit ${layer.name}`}>
            <PageHeader title={`Edit ${layer.name}`} />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Layer name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Description">
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <Field label="Project">
                    <Select value={form.data.project_id || ''} onChange={(event) => form.setData('project_id', event.target.value)}>
                        <option value="">No project</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <p className="font-mono text-muted">Table {layer.table_name} · {layer.geometry_type || 'geometry not detected'}</p>
                <div className="flex gap-3">
                    <GhostLink href={`/layers/${layer.id}`}>Cancel</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Update layer
                    </PrimaryButton>
                </div>
            </form>
            <form onSubmit={saveStyle} className="mt-8 max-w-xl space-y-4 border-t border-line pt-6">
                <Heading as="h2" >Style</Heading>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Fill">
                        <input type="color" className="w-full bg-field" value={form.data.fill_color} onChange={(event) => form.setData('fill_color', event.target.value)} />
                    </Field>
                    <Field label="Stroke">
                        <input type="color" className="w-full bg-field" value={form.data.stroke_color} onChange={(event) => form.setData('stroke_color', event.target.value)} />
                    </Field>
                    <Field label="Stroke width">
                        <TextInput type="number" min="1" max="10" value={form.data.stroke_width} onChange={(event) => form.setData('stroke_width', event.target.value)} />
                    </Field>
                    <Field label="Fill opacity">
                        <TextInput type="number" min="0" max="1" step="0.1" value={form.data.fill_opacity} onChange={(event) => form.setData('fill_opacity', event.target.value)} />
                    </Field>
                </div>
                <PrimaryButton type="submit">Save style</PrimaryButton>
            </form>
        </AppLayout>
    );
}
