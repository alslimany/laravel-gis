import { Link } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { DangerButton, PageHeader, Panel, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ users }) {
    const { t } = useTranslation();
    const rows = users?.data || [];

    return (
        <AppLayout title={t('users.title')}>
            <PageHeader title={t('users.title')} />
            <Panel>
                <DataTable>
                    <Table.Header>
                        <Table.Row>
                            <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                            <Table.Head className={thClass}>{t('forms.email')}</Table.Head>
                            <Table.Head className={`${thClass} hidden md:table-cell`}>{t('organization.title')}</Table.Head>
                            <Table.Head className={thClass}>Roles</Table.Head>
                            <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {rows.map((person) => (
                            <Table.Row key={person.id}>
                                <Table.Cell className={tdClass}>{person.name}</Table.Cell>
                                <Table.Cell className={tdClass}>{person.email}</Table.Cell>
                                <Table.Cell className={`${tdClass} hidden md:table-cell`}>{person.organization?.name || t('common.em_dash')}</Table.Cell>
                                <Table.Cell className={tdMono}>{(person.roles || []).map((role) => role.name).join(', ')}</Table.Cell>
                                <Table.Cell className={tdClass}>
                                    <Link href={`/users/${person.id}/edit`} className="me-3 font-medium text-primary hover:underline">
                                        Edit
                                    </Link>
                                    <DangerButton onClick={() => destroyResource(`/users/${person.id}`, 'Delete this user?')}>{t('actions.delete')}</DangerButton>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </DataTable>
            </Panel>
        </AppLayout>
    );
}
