import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ dashboards }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props;
    const rows = dashboards?.data || [];

    return (
        <AppLayout title={t('dashboards.title')}>
            <PageHeader
                title={t('dashboards.title')}
                action={
                    abilities?.edit ? (
                        <PrimaryLink href="/dashboards/create">
                            {t('dash.new')}
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>{t('dash.empty')}</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={`${thClass}`}>{t('forms.name')}</Table.Head>
                                <Table.Head className={`${thClass}`}>{t('dash.widgets')}</Table.Head>
                                <Table.Head className={`${thClass}`}>{t('dash.visibility')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{t('layers.updated')}</Table.Head>
                                <Table.Head className={`${thClass}`}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((board) => (
                                <Table.Row key={board.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/dashboards/${board.id}`} className="font-medium text-primary hover:underline">
                                            {board.name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={tdMono}>{Array.isArray(board.widgets) ? board.widgets.length : 0}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={board.is_public}>{board.is_public ? t('common.public') : t('common.private')}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{when(board.updated_at)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/dashboards/${board.id}`}>{t('actions.view')}</ActionLink>
                                            <ActionLink href={`/dashboards/${board.id}/edit`}>{t('actions.edit')}</ActionLink>
                                            <DangerButton onClick={() => destroyResource(`/dashboards/${board.id}`, t('dash.confirm_delete'))}>{t('actions.delete')}</DangerButton>
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={dashboards} />
            </Panel>
        </AppLayout>
    );
}
