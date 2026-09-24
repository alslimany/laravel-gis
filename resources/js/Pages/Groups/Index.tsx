import { Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMono } from '@/components/gis';

export default function Index({ groups }) {
    const { abilities } = usePage().props;
    const rows = groups?.data || [];

    return (
        <AppLayout title="Groups">
            <PageHeader
                title="Groups"
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
                                <Table.Head className={thClass}>Name</Table.Head>
                                <Table.Head className={thClass}>Members</Table.Head>
                                <Table.Head className={thClass}>Actions</Table.Head>
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
                                            <ActionLink href={`/groups/${group.id}`}>View</ActionLink>
                                            {abilities?.admin ? <ActionLink href={`/groups/${group.id}/edit`}>Edit</ActionLink> : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/groups/${group.id}`, 'Delete this group?')}>Delete</DangerButton>
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
