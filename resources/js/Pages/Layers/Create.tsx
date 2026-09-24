import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, Panel, PrimaryButton, Select, TextArea, TextInput } from '@/components/gis';

const GEOMETRIES = ['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'];

type ProjectOption = { id: number; name: string };

function tableNameFrom(name: string) {
    const slug = name
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    if (!slug) {
        return '';
    }

    return /^[0-9]/.test(slug) ? `layer_${slug}` : slug;
}

export default function Create({ projects = [] }: { projects?: ProjectOption[] }) {
    const form = useForm({
        name: '',
        description: '',
        project_id: '',
        table_name: '',
        geometry_type: '',
    });
    const [tableTouched, setTableTouched] = useState(false);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post('/layers');
    }

    return (
        <AppLayout title="New layer">
            <PageHeader title="New layer" action={<GhostLink href="/layers">Back</GhostLink>} />
            <Panel className="max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Point this layer at a PostGIS table. Publishing it later sends that table to GeoServer.
                    </p>
                    <Field label="Layer name" error={form.errors.name}>
                        <TextInput
                            value={form.data.name}
                            required
                            onChange={(event) => {
                                const name = event.target.value;
                                form.setData('name', name);
                                if (!tableTouched) {
                                    form.setData('table_name', tableNameFrom(name));
                                }
                            }}
                        />
                    </Field>
                    <Field label="Description" error={form.errors.description}>
                        <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                    </Field>
                    <Field label="Project" error={form.errors.project_id}>
                        <Select value={form.data.project_id} onChange={(event) => form.setData('project_id', event.target.value)}>
                            <option value="">No project</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Table name" error={form.errors.table_name} hint="The PostGIS table that holds this layer. Filled from the layer name until you edit it.">
                        <TextInput
                            value={form.data.table_name}
                            required
                            onChange={(event) => {
                                setTableTouched(true);
                                form.setData('table_name', event.target.value);
                            }}
                        />
                    </Field>
                    <Field label="Geometry type" error={form.errors.geometry_type} hint="Leave this on auto detect when the table already has a geometry column.">
                        <Select value={form.data.geometry_type} onChange={(event) => form.setData('geometry_type', event.target.value)}>
                            <option value="">Auto detect</option>
                            {GEOMETRIES.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="flex gap-3">
                        <GhostLink href="/layers">Cancel</GhostLink>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating…' : 'Create layer'}
                        </PrimaryButton>
                    </div>
                </form>
            </Panel>
        </AppLayout>
    );
}
