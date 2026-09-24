import { useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Field, GhostLink, PageHeader, PrimaryButton, TextArea, TextInput } from '@/components/gis';

export default function Form({ group = null }) {
    const editing = Boolean(group);
    const form = useForm({ name: group?.name || '', description: group?.description || '' });

    function submit(event) {
        event.preventDefault();
        if (editing) form.put(`/groups/${group.id}`);
        else form.post('/groups');
    }

    return (
        <AppLayout title={editing ? `Edit ${group.name}` : 'New group'}>
            <PageHeader title={editing ? `Edit ${group.name}` : 'New group'} />
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <Field label="Name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Description">
                    <TextArea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
                <div className="flex gap-3">
                    <GhostLink href={editing ? `/groups/${group.id}` : '/groups'}>Cancel</GhostLink>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Update group' : 'Create group'}
                    </PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
