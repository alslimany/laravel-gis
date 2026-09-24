import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { count, when } from '../../lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryButton, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '../../Components/ui';

export default function Index({ layers }) {
    const { abilities } = usePage().props;
    const rows = layers?.data || [];

    return (
        <AppLayout title="Layers">
            <PageHeader
                title="Layers"
                action={
                    abilities?.edit ? (
                        <PrimaryLink href="/layers/create">
                            New layer
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>
                        No layers yet.{' '}
                        {abilities?.edit ? (
                            <Link href="/layers/create" className="font-medium text-primary hover:underline">
                                Create a layer
                            </Link>
                        ) : null}
                    </Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>Name</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Project</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Type</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>Features</Table.Head>
                                <Table.Head className={thClass}>Status</Table.Head>
                                <Table.Head className={thClass}>Actions</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((layer) => (
                                <Table.Row key={layer.id}>
                                    <Table.Cell className={tdClass}>
                                        <Link href={`/layers/${layer.id}`} className="font-medium text-primary hover:underline">
                                            {layer.name}
                                        </Link>
                                    </Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{layer.project?.name || '—'}</Table.Cell>
                                    <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{layer.geometry_type || '—'}</Table.Cell>
                                    <Table.Cell className={`${tdMono} hidden md:table-cell`}>{count(layer.feature_count)}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={layer.published}>{layer.published ? 'Published' : 'Draft'}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/layers/${layer.id}`}>View</ActionLink>
                                            {abilities?.edit ? <ActionLink href={`/layers/${layer.id}/edit`}>Edit</ActionLink> : null}
                                            {abilities?.admin ? (
                                                <DangerButton onClick={() => destroyResource(`/layers/${layer.id}`, 'Delete this layer?')}>Delete</DangerButton>
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
