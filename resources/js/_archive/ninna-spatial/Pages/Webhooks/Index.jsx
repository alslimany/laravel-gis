import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '../../Components/ui';

export default function Index({ webhooks }) {
    const { abilities } = usePage().props;
    const rows = webhooks?.data || [];

    return (
        <AppLayout title="Webhooks">
            <PageHeader
                title="Webhooks"
                action={
                    abilities?.admin ? (
                        <PrimaryLink href="/webhooks/create">
                            New webhook
                        </PrimaryLink>
                    ) : null
                }
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>No webhooks yet.</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>Name</Table.Head>
                                <Table.Head className={thClass}>URL</Table.Head>
                                <Table.Head className={thClass}>Status</Table.Head>
                                <Table.Head className={thClass}>Actions</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((hook) => (
                                <Table.Row key={hook.id}>
                                    <Table.Cell className={tdClass}>{hook.name}</Table.Cell>
                                    <Table.Cell className={`${tdMono} max-w-xs truncate`}>{hook.url}</Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <Pill live={hook.is_active}>{hook.is_active ? 'Active' : 'Paused'}</Pill>
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        <RowActions>
                                            <ActionLink href={`/webhooks/${hook.id}/edit`}>Edit</ActionLink>
                                            <DangerButton onClick={() => destroyResource(`/webhooks/${hook.id}`, 'Delete this webhook?')}>Delete</DangerButton>
                                        </RowActions>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={webhooks} />
            </Panel>
        </AppLayout>
    );
}
