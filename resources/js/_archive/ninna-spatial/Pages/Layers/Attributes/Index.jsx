import { router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Empty, GhostLink, PageHeader, Pager, Panel, PrimaryButton, Table, TextInput, DataTable, thClass, tdClass, tdMuted, tdMono } from '../../../Components/ui';

export default function Index({ layer, features, columns = [], columnLabels = {} }) {
    const [search, setSearch] = useState('');
    const rows = features?.data || [];

    function submit(event) {
        event.preventDefault();
        router.get(`/layers/${layer.id}/attributes`, { search }, { preserveState: true });
    }

    return (
        <AppLayout title={`${layer.name} attributes`}>
            <PageHeader title={layer.name} action={<GhostLink href={`/layers/${layer.id}`}>Back to layer</GhostLink>} />
            <form onSubmit={submit} className="mb-4 flex max-w-xl gap-2">
                <TextInput value={search} placeholder="Search attributes" onChange={(event) => setSearch(event.target.value)} />
                <PrimaryButton type="submit">Search</PrimaryButton>
            </form>
            <Panel>
                {rows.length === 0 ? (
                    <Empty>No features match this search.</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                {columns.map((column) => (
                                    <Table.Head key={column} className={thClass}>
                                        {columnLabels[column] || column}
                                    </Table.Head>
                                ))}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((feature, index) => (
                                <Table.Row key={feature.id || index}>
                                    {columns.map((column) => (
                                        <Table.Cell key={column} className={`${tdMono} max-w-xs truncate`}>
                                            {feature[column] == null ? '—' : String(feature[column])}
                                        </Table.Cell>
                                    ))}
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={features} />
            </Panel>
        </AppLayout>
    );
}
