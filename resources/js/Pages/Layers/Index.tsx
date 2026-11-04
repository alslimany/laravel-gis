import { enumLabel } from '@/lib/enum-labels';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { count, when } from '@/lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

type LayerRow = {
    id: number;
    name: string;
    published?: boolean;
    geometry_type?: string | null;
    feature_count?: number | null;
    created_at?: string | null;
    project?: { name?: string | null } | null;
};

export default function Index({ layers }: { layers?: { data?: LayerRow[]; links?: Array<{ url: string | null; label: string; active: boolean }> } }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props as { abilities?: { edit?: boolean; admin?: boolean } };
    const rows = layers?.data || [];

    return (
        <AppLayout title={t('layers.title')}>
            <PageHeader
                title={t('layers.title')}
                action={
                    <div className="flex flex-wrap items-center gap-4">
                        <ActionLink href="/relationships">{t('relationships.builder')}</ActionLink>
                        {abilities?.edit ? <PrimaryLink href="/layers/create">{t('layers.new')}</PrimaryLink> : null}
                    </div>
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>
                        {__('layers.idx.empty')}{' '}
                        {abilities?.edit ? (
                            <Link href="/layers/create" className="font-medium text-primary hover:underline">
                                {__('layers.idx.create')}
                            </Link>
                        ) : null}
                    </Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{t('layers.project')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{t('layers.geometry')}</Table.Head>
                                <Table.Head className={`${thClass} hidden lg:table-cell`}>{t('layers.features')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.status')}</Table.Head>
                                <Table.Head className={`${thClass} hidden lg:table-cell`}>{t('layers.added')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((layer) => (
                                <Table.Row key={layer.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/layers/${layer.id}`} className="font-medium text-primary hover:underline">
                                            {layer.name}
                                        </Link>
                                        <p className="mt-0.5 text-xs text-muted-foreground md:hidden">
                                            {layer.project?.name || t('layers.no_project')} · {enumLabel(layer.geometry_type) || t('layers.geometry_not_set')}
                                        </p>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{layer.project?.name || t('common.em_dash')}</Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{enumLabel(layer.geometry_type) || t('common.em_dash')}</Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden lg:table-cell`}>{count(layer.feature_count)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={layer.published}>{layer.published ? t('layers.published') : __('layers.idx.draft')}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden lg:table-cell`}>{when(layer.created_at)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/layers/${layer.id}`}>{t('actions.view')}</ActionLink>
                                            {abilities?.edit ? <ActionLink href={`/layers/${layer.id}/edit`}>{t('actions.edit')}</ActionLink> : null}
                                            {abilities?.edit && layer.geometry_type !== 'Raster' ? (
                                                <ActionLink href={`/layers/${layer.id}/builder`}>{t('layers.builder')}</ActionLink>
                                            ) : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/layers/${layer.id}`, __('layers.idx.confirm_delete'))}>{t('actions.delete')}</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={layers} />
            </Panel>
        </AppLayout>
    );
}
