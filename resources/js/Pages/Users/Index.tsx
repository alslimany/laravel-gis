import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { DangerButton, PageHeader, Panel, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

export default function Index({ users }) {
    const rows = users?.data || [];

    return (
        <AppLayout title="Users">
            <PageHeader title="Users" />
            <Panel>
                <DataTable>
                    <Table.Header>
                        <Table.Row>
                            <Table.Head className={thClass}>Name</Table.Head>
                            <Table.Head className={thClass}>Email</Table.Head>
                            <Table.Head className={`${thClass} hidden md:table-cell`}>Organization</Table.Head>
                            <Table.Head className={thClass}>Roles</Table.Head>
                            <Table.Head className={thClass}>Actions</Table.Head>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {rows.map((person) => (
                            <Table.Row key={person.id}>
                                <Table.Cell className={tdClass}>{person.name}</Table.Cell>
                                <Table.Cell className={tdClass}>{person.email}</Table.Cell>
                                <Table.Cell className={`${tdClass} hidden md:table-cell`}>{person.organization?.name || '—'}</Table.Cell>
                                <Table.Cell className={tdMono}>{(person.roles || []).map((role) => role.name).join(', ')}</Table.Cell>
                                <Table.Cell className={tdClass}>
                                    <Link href={`/users/${person.id}/edit`} className="mr-3 font-medium text-primary hover:underline">
                                        Edit
                                    </Link>
                                    <DangerButton onClick={() => destroyResource(`/users/${person.id}`, 'Delete this user?')}>Delete</DangerButton>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </DataTable>
            </Panel>
        </AppLayout>
    );
}
