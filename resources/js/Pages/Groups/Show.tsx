import { router, useForm } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { DangerButton, Field, GhostLink, PageHeader, PrimaryButton, Select, Table, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Show({ group, availableUsers = [], canManageMembers = false }) {
    const { t } = useTranslation();
    const form = useForm({ user_id: '', role: 'member' });

    return (
        <AppLayout title={group.name}>
            <PageHeader
                title={group.name}
                action={
                    <div className="flex gap-2">
                        {canManageMembers ? <GhostLink href={`/groups/${group.id}/edit`}>{t('actions.edit')}</GhostLink> : null}
                        <GhostLink href="/groups">{t('actions.back')}</GhostLink>
                    </div>
                }
            />
            <p className="mb-4 text-muted-foreground">{group.description || __('groups.no_description')}</p>
            {canManageMembers ? (
                <form
                    className="mb-6 grid max-w-3xl gap-3 sm:grid-cols-[1fr_160px_auto] sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/groups/${group.id}/users`);
                    }}
                >
                    <Field label={t('users.add_member')}>
                        <Select value={form.data.user_id} required onChange={(event) => form.setData('user_id', event.target.value)}>
                            <option value="">{__('groups.select_user')}</option>
                            {availableUsers.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label={__('invite.role')}>
                        <Select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                            <option value="member">{__('roles.member')}</option>
                            <option value="admin">{t('modules.admin.label')}</option>
                        </Select>
                    </Field>
                    <PrimaryButton type="submit" disabled={availableUsers.length === 0}>
                        {__('invite.add')}
                    </PrimaryButton>
                </form>
            ) : null}
            <DataTable>
                <Table.Header>
                    <Table.Row>
                        <Table.Head className={thClass}>{__('roles.member')}</Table.Head>
                        <Table.Head className={thClass}>{__('invite.role')}</Table.Head>
                        <Table.Head className={`${thClass} text-end`}>{t('forms.actions')}</Table.Head>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {(group.users || []).map((member) => (
                        <Table.Row key={member.id}>
                            <Table.Cell className={tdClass}>
                                {member.name}
                                <div className="text-[12px] text-muted-foreground">{member.email}</div>
                            </Table.Cell>
                            <Table.Cell className={tdClass}>
                                {canManageMembers ? (
                                    <Select
                                        value={member.pivot?.role || 'member'}
                                        onChange={(event) => router.put(`/groups/${group.id}/users/${member.id}/role`, { role: event.target.value })}
                                    >
                                        <option value="member">{__('roles.member')}</option>
                                        <option value="admin">{t('modules.admin.label')}</option>
                                    </Select>
                                ) : (
                                    member.pivot?.role
                                )}
                            </Table.Cell>
                            <Table.Cell className={`${tdClass} text-end`}>
                                {canManageMembers ? (
                                    <DangerButton
                                        onClick={() => {
                                            if (window.confirm(__('groups.confirm_remove'))) {
                                                router.delete(`/groups/${group.id}/users/${member.id}`);
                                            }
                                        }}
                                    >
                                        {__('invite.remove')}
                                    </DangerButton>
                                ) : null}
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </DataTable>
        </AppLayout>
    );
}
