import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMono } from '@/components/gis';

export default function Index({ groups }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props;
    const rows = groups?.data || [];

    return (
        <AppLayout title={t('groups.title')}>
            <PageHeader
                title={t('groups.title')}
                action={
                    abilities?.admin ? (
                        <PrimaryLink href="/groups/create">
                            New group
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>No groups yet.</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                <Table.Head className={thClass}>{t('groups.members')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((group) => (
                                <Table.Row key={group.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/groups/${group.id}`} className="font-medium text-primary hover:underline">
                                            {group.name}
                                        </Link>
                                        {group.description ? <div className="text-[12px] text-muted-foreground">{group.description}</div> : null}
                                    </Table.Cell>
                                    <Table.Cell className={tdMono}>{group.users_count ?? 0}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/groups/${group.id}`}>{t('actions.view')}</ActionLink>
                                            {abilities?.admin ? <ActionLink href={`/groups/${group.id}/edit`}>{t('actions.edit')}</ActionLink> : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/groups/${group.id}`, 'Delete this group?')}>{t('actions.delete')}</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={groups} />
            </Panel>
        </AppLayout>
    );
}
