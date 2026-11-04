import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ webhooks }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props;
    const rows = webhooks?.data || [];

    return (
        <AppLayout title={t('webhooks.title')}>
            <PageHeader
                title={t('webhooks.title')}
                action={
                    abilities?.admin ? (
                        <PrimaryLink href="/webhooks/create">
                            New webhook
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>No webhooks yet.</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                <Table.Head className={thClass}>URL</Table.Head>
                                <Table.Head className={thClass}>{t('forms.status')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((hook) => (
                                <Table.Row key={hook.id}>
                                    <Table.Cell className={tdClass}>{hook.name}</Table.Cell>
                                    <Table.Cell className={`${tdMono} max-w-xs truncate`}>{hook.url}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={hook.is_active}>{hook.is_active ? 'Active' : 'Paused'}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/webhooks/${hook.id}/edit`}>{t('actions.edit')}</ActionLink>
                                            <DangerButton onClick={() => destroyResource(`/webhooks/${hook.id}`, 'Delete this webhook?')}>{t('actions.delete')}</DangerButton>
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={webhooks} />
            </Panel>
        </AppLayout>
    );
}
