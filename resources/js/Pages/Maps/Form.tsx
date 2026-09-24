import { useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, PrimaryButton, TextArea, TextInput } from '@/components/gis';

export default function Form({ map = null }) {
    const editing = Boolean(map);
    const form = useForm({
        name: map?.name || '',
        description: map?.description || '',
        is_public: Boolean(map?.is_public),
    });

    function submit(event) {
        event.preventDefault();
        if (editing) {
            form.put(`/maps/${map.id}`);
        } else {
            form.post('/maps');
        }
    }

    return (
        <AppLayout title={editing ? `Edit ${map.name}` : 'New map'}>
            <PageHeader title={editing ? `Edit ${map.name}` : 'New map'} />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Description">
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.is_public} onChange={(event) => form.setData('is_public', event.target.checked)} />
                    Public
                </label>
                <div className="flex gap-3">
                    <GhostLink href={editing ? `/maps/${map.id}` : '/maps'}>Cancel</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Update map' : 'Create map'}
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
