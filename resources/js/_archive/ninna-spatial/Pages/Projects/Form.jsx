import { useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Field, GhostLink, PageHeader, PrimaryButton, TextArea, TextInput } from '../../Components/ui';

export default function Form({ project = null }) {
    const editing = Boolean(project);
    const form = useForm({
        name: project?.name || '',
        description: project?.description || '',
        is_public: Boolean(project?.is_public),
    });

    function submit(event) {
        event.preventDefault();
        if (editing) {
            form.put(`/projects/${project.id}`);
        } else {
            form.post('/projects');
        }
    }

    return (
        <AppLayout title={editing ? `Edit ${project.name}` : 'New project'}>
            <PageHeader title={editing ? `Edit ${project.name}` : 'New project'} />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Project name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Description">
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.is_public} onChange={(event) => form.setData('is_public', event.target.checked)} />
                    Make this project public
                </label>
                <div className="flex gap-3">
                    <GhostLink href={editing ? `/projects/${project.id}` : '/projects'}>Cancel</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Update project' : 'Create project'}
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
