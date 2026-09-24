import { router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { DangerButton, Field, GhostLink, PageHeader, PrimaryButton, Select, Table, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Invite({ project, availableUsers = [], collaborators = [] }) {
    const form = useForm({ user_id: '', role: 'viewer' });

    function submit(event) {
        event.preventDefault();
        form.post(`/projects/${project.id}/invite`);
    }

    return (
        <AppLayout title={`Invite · ${project.name}`}>
            <PageHeader title={`Collaborators · ${project.name}`} action={<GhostLink href={`/projects/${project.id}`}>Back</GhostLink>} />
            {availableUsers.length ? (
                <form onSubmit={submit} className="mb-6 grid max-w-3xl gap-3 sm:grid-cols-[1fr_160px_auto] sm:items-end">
                    <Field label="User" error={form.errors.user_id}>
                        <Select value={form.data.user_id} required onChange={(event) => form.setData('user_id', event.target.value)}>
                            <option value="">Choose a user</option>
                            {availableUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name} ({user.email})
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Role">
                        <Select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                            <option value="viewer">Viewer</option>
                            <option value="editor">Editor</option>
                        </Select>
                    </Field>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Add
                    </PrimaryButton>
                </form>
            ) : (
                <p className="mb-4 text-muted-foreground">No other users in this organization are available to invite.</p>
            )}
            <DataTable>
                <Table.Header>
                    <Table.Row>
                        <Table.Head className={thClass}>Name</Table.Head>
                        <Table.Head className={thClass}>Role</Table.Head>
                        <Table.Head className={thClass}>Added</Table.Head>
                        <Table.Head className={thClass}>Actions</Table.Head>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {collaborators.map((person) => (
                        <Table.Row key={person.id}>
                            <Table.Cell className={tdClass}>
                                {person.name}
                                <div className="text-[12px] text-muted-foreground">{person.email}</div>
                            </Table.Cell>
                            <Table.Cell className={tdClass}>
                                <Select
                                    value={person.pivot?.role || 'viewer'}
                                    onChange={(event) => router.put(`/projects/${project.id}/collaborators/${person.id}/role`, { role: event.target.value })}
                                >
                                    <option value="viewer">Viewer</option>
                                    <option value="editor">Editor</option>
                                    <option value="owner">Owner</option>
                                </Select>
                            </Table.Cell>
                            <Table.Cell className={tdMuted}>{when(person.pivot?.created_at)}</Table.Cell>
                            <Table.Cell className={tdClass}>
                                <DangerButton
                                    onClick={() => {
                                        if (window.confirm(`Remove ${person.name} from this project?`)) {
                                            router.delete(`/projects/${project.id}/collaborators/${person.id}`);
                                        }
                                    }}
                                >
                                    Remove
                                </DangerButton>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </DataTable>
        </AppLayout>
    );
}
