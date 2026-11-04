import { Link, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { count, when } from '@/lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ forms }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props;
    const rows = forms?.data || [];

    return (
        <AppLayout title={t('forms_page.title')}>
            <PageHeader
                title={t('forms_page.title')}
                action={
                    abilities?.edit ? (
                        <PrimaryLink href="/forms/create">
                            {__('forms.idx.new')}
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>{__('forms.idx.empty')}</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Layer</Table.Head>
                                <Table.Head className={`${thClass} hidden sm:table-cell`}>Submissions</Table.Head>
                                <Table.Head className={thClass}>Visibility</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{t('projects.created')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((form) => (
                                <Table.Row key={form.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/forms/${form.id}`} className="font-medium text-primary hover:underline">
                                            {form.name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdClass} hidden md:table-cell`}>{form.layer?.name || 'Standalone'}</Table.Cell>
                                    <Table.Cell className={`${tdClass} hidden sm:table-cell`}>{count(form.submissions_count)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={form.is_public}>{form.is_public ? t('common.public') : t('common.private')}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{when(form.created_at)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/forms/${form.id}`}>{t('actions.view')}</ActionLink>
                                            {abilities?.edit ? <ActionLink href={`/forms/${form.id}/edit`}>{t('actions.edit')}</ActionLink> : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/forms/${form.id}`, 'Delete this form?')}>{t('actions.delete')}</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={forms} />
            </Panel>
        </AppLayout>
    );
}
