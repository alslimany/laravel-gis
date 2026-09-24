import { useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { Field, GhostLink, PageHeader, PrimaryButton, TextInput } from '../../Components/ui';

export default function Edit({ user, roles = [] }) {
    const form = useForm({
        name: user.name || '',
        email: user.email || '',
        roles: (user.roles || []).map((role) => role.id),
    });

    function toggle(id) {
        const next = form.data.roles.includes(id) ? form.data.roles.filter((role) => role !== id) : [...form.data.roles, id];
        form.setData('roles', next);
    }

    return (
        <AppLayout title={`Edit ${user.name}`}>
            <PageHeader title={`Edit ${user.name}`} action={<GhostLink href="/users">Back</GhostLink>} />
            <form
                className="max-w-xl space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/users/${user.id}`);
                }}
            >
                <Field label="Name" error={form.errors.name}>
                    <TextInput value={form.data.name} required onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label="Email" error={form.errors.email}>
                    <TextInput type="email" value={form.data.email} required onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <fieldset>
                    <legend className="mb-2 font-medium text-copy">Roles</legend>
                    {roles.map((role) => (
                        <label key={role.id} className="flex items-center gap-2">
                            <input type="checkbox" checked={form.data.roles.includes(role.id)} onChange={() => toggle(role.id)} />
                            {role.name}
                        </label>
                    ))}
                </fieldset>
                <p className="text-muted">Organization {user.organization?.name || 'None'}</p>
                <PrimaryButton type="submit" disabled={form.processing}>
                    Update user
                </PrimaryButton>
            </form>
        </AppLayout>
    );
}
