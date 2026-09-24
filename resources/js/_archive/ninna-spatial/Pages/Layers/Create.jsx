import { useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Field, GhostLink, PageHeader, PrimaryButton, Select, TextArea, TextInput } from '../../Components/ui';

const GEOMETRIES = ['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'];

export default function Create({ projects = [] }) {
    const form = useForm({
        name: '',
        description: '',
        project_id: '',
        table_name: '',
        geometry_type: '',
    });

    function submit(event) {
        event.preventDefault();
        form.post('/layers');
    }

    return (
        <AppLayout title="New layer">
            <PageHeader title="New layer" />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Layer name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
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
                <Field label="Table name" error={form.errors.table_name} hint="The PostGIS table that holds this layer.">
                    <TextInput value={form.data.table_name} required onChange={(event) => form.setData('table_name', event.target.value)} />
                </Field>
                <Field label="Geometry type" error={form.errors.geometry_type}>
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
                        Create layer
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
