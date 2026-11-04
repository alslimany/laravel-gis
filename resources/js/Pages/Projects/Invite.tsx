import { router, useForm } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { DangerButton, Field, GhostLink, PageHeader, PrimaryButton, Select, Table, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Invite({ project, availableUsers = [], collaborators = [] }) {
    const { t } = useTranslation();
    const form = useForm({ user_id: '', role: 'viewer' });

    function submit(event) {
        event.preventDefault();
        form.post(`/projects/${project.id}/invite`);
    }

    return (
        <AppLayout title={__('invite.title', { name: project.name })}>
            <PageHeader title={__('invite.heading', { name: project.name })} action={<GhostLink href={`/projects/${project.id}`}>{t('actions.back')}</GhostLink>} />
            {availableUsers.length ? (
                <form onSubmit={submit} className="mb-6 grid max-w-3xl gap-3 sm:grid-cols-[1fr_160px_auto] sm:items-end">
                    <Field label={__('invite.user')} error={form.errors.user_id}>
                        <Select value={form.data.user_id} required onChange={(event) => form.setData('user_id', event.target.value)}>
                            <option value="">{t('users.choose_user')}</option>
                            {availableUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name} ({user.email})
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label={__('invite.role')}>
                        <Select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                            <option value="viewer">{__('roles.viewer')}</option>
                            <option value="editor">{t('forms.editor')}</option>
                        </Select>
                    </Field>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {__('invite.add')}
                    </PrimaryButton>
                </form>
            ) : (
                <p className="mb-4 text-muted-foreground">{__('invite.none')}</p>
            )}
            <DataTable>
                <Table.Header>
                    <Table.Row>
                        <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                        <Table.Head className={thClass}>{__('invite.role')}</Table.Head>
                        <Table.Head className={thClass}>{t('layers.added')}</Table.Head>
                        <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
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
                                    <option value="viewer">{__('roles.viewer')}</option>
                                    <option value="editor">{t('forms.editor')}</option>
                                    <option value="owner">{__('roles.owner')}</option>
                                </Select>
                            </Table.Cell>
                            <Table.Cell className={tdMuted}>{when(person.pivot?.created_at)}</Table.Cell>
                            <Table.Cell className={tdClass}>
                                <DangerButton
                                    onClick={() => {
                                        if (window.confirm(__('invite.confirm_remove', { name: person.name }))) {
                                            router.delete(`/projects/${project.id}/collaborators/${person.id}`);
                                        }
                                    }}
                                >
                                    {__('invite.remove')}
                                </DangerButton>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </DataTable>
        </AppLayout>
    );
}
