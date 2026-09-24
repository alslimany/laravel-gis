import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ maps }) {
    const rows = maps?.data || [];

    return (
        <AppLayout title="Maps">
            <PageHeader title="Maps" action={<PrimaryLink href="/maps/builder">Open map builder</PrimaryLink>} />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>
                        No maps yet.{' '}
                        <PrimaryLink href="/maps/builder">Open map builder</PrimaryLink>
                    </Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>Name</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Description</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Created by</Table.Head>
                                <Table.Head className={thClass}>Status</Table.Head>
                                <Table.Head className={thClass}>Actions</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((map) => (
                                <Table.Row key={map.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/maps/${map.id}`} className="font-medium text-primary hover:underline">
                                            {map.name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden max-w-xs truncate md:table-cell`}>{map.description || '—'}</Table.Cell>
                                    <Table.Cell className={`${tdClass} hidden md:table-cell`}>{map.user?.name || '—'}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={map.is_public}>{map.is_public ? 'Public' : 'Private'}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/maps/${map.id}`} hideOnPhone>
                                                View
                                            </ActionLink>
                                            <ActionLink href={`/maps/builder/${map.id}`}>Edit</ActionLink>
                                            <ActionLink href={`/maps/${map.id}/share`}>Share</ActionLink>
                                            <DangerButton onClick={() => destroyResource(`/maps/${map.id}`, 'Delete this map?')}>Delete</DangerButton>
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={maps} />
            </Panel>
        </AppLayout>
    );
}
