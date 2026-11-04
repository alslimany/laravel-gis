import { Link, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ projects }) {
    const { t } = useTranslation();
    const { abilities } = usePage().props;
    const rows = projects?.data || [];

    return (
        <AppLayout title={t('projects.title')}>
            <PageHeader
                title={t('projects.title')}
                action={
                    abilities?.edit ? (
                        <PrimaryLink href="/projects/create">
                            {__('projects.new')}
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>{__('projects.empty')}</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{t('layers.title')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{__('roles.owner')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.status')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((project) => (
                                <Table.Row key={project.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/projects/${project.id}`} className="font-medium text-primary hover:underline">
                                            {project.name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden md:table-cell`}>{project.layers?.length ?? project.layers_count ?? 0}</Table.Cell>
                                    <Table.Cell className={`${tdClass} hidden md:table-cell`}>{project.user?.name || t('common.em_dash')}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={project.is_public}>{project.is_public ? t('common.public') : t('common.private')}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/projects/${project.id}`} hideOnPhone>
                                                {__('projects.view')}
                                            </ActionLink>
                                            {abilities?.edit ? <ActionLink href={`/projects/${project.id}/edit`}>{t('actions.edit')}</ActionLink> : null}
                                            {abilities?.edit ? (
                                                <ActionLink href={`/projects/${project.id}/share`} hideOnPhone>
                                                    {__('projects.share')}
                                                </ActionLink>
                                            ) : null}
                                            {abilities?.edit ? (
                                                <ActionLink href={`/projects/${project.id}/invite`} hideOnPhone>
                                                    {__('projects.invite')}
                                                </ActionLink>
                                            ) : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/projects/${project.id}`, __('projects.confirm_delete'))}>{t('actions.delete')}</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={projects} />
            </Panel>
        </AppLayout>
    );
}
