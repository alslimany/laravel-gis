import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { when } from '../../lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '../../Components/ui';

export default function Index({ dashboards }) {
    const { abilities } = usePage().props;
    const rows = dashboards?.data || [];

    return (
        <AppLayout title="Dashboards">
            <PageHeader
                title="Dashboards"
                action={
                    abilities?.edit ? (
                        <PrimaryLink href="/dashboards/create">
                            New dashboard
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>No dashboards yet.</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={`${thClass}`}>Name</Table.Head>
                                <Table.Head className={`${thClass}`}>Widgets</Table.Head>
                                <Table.Head className={`${thClass}`}>Visibility</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Updated</Table.Head>
                                <Table.Head className={`${thClass}`}>Actions</Table.Head>
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
                                        <Pill live={board.is_public}>{board.is_public ? 'Public' : 'Private'}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{when(board.updated_at)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/dashboards/${board.id}`}>View</ActionLink>
                                            <ActionLink href={`/dashboards/${board.id}/edit`}>Edit</ActionLink>
                                            <DangerButton onClick={() => destroyResource(`/dashboards/${board.id}`, 'Delete this dashboard?')}>Delete</DangerButton>
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
