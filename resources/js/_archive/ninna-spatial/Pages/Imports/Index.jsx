import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { bytes, count, when } from '../../lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '../../Components/ui';

const TONES = {
    completed: 'live',
    processing: 'run',
    failed: 'fail',
    pending: 'idle',
};

export default function Index({ imports }) {
    const rows = imports?.data || [];

    return (
        <AppLayout title="Data imports">
            <PageHeader
                title="Data imports"
                action={
                    <PrimaryLink href="/imports/create">
                        New import
                    </PrimaryLink>
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>
                        No imports yet.{' '}
                        <Link href="/imports/create" className="font-medium text-primary hover:underline">
                            Import a dataset
                        </Link>
                    </Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>File</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Type</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Size</Table.Head>
                                <Table.Head className={thClass}>Status</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Progress</Table.Head>
                                <Table.Head className={thClass}>Actions</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((item) => (
                                <Table.Row key={item.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/imports/${item.id}`} className="font-medium text-primary hover:underline">
                                            {item.file_name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden md:table-cell`}>{String(item.file_type || '').toUpperCase()}</Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden md:table-cell`}>{bytes(item.file_size)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill tone={TONES[item.status] || 'idle'}>{item.status}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden md:table-cell`}>{count(item.progress)}%</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/imports/${item.id}`}>View</ActionLink>
                                            {item.status === 'failed' || item.status === 'completed' ? (
                                                <DangerButton onClick={() => destroyResource(`/imports/${item.id}`, 'Delete this import?')}>Delete</DangerButton>
                                            ) : null}
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={imports} />
            </Panel>
        </AppLayout>
    );
}
