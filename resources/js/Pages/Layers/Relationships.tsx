import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import {
    DangerButton,
    DataTable,
    Empty,
    GhostLink,
    PageHeader,
    Panel,
    PrimaryLink,
    RowActions,
    Table,
    tdClass,
    tdMono,
    thClass,
} from '@/components/gis';

type LayerRecord = {
    id: number;
    name: string;
    table_name?: string | null;
    geometry_type?: string | null;
};

type RelationshipRecord = {
    id: number;
    name: string;
    foreign_key: string;
    related_layer?: LayerRecord | null;
};

export default function Relationships({
    layer,
    relationships = [],
}: {
    layer: LayerRecord;
    relationships?: RelationshipRecord[];
}) {
    const { abilities } = usePage().props as { abilities?: { edit?: boolean } };
    const canEdit = Boolean(abilities?.edit);

    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'X-Requested-With': 'XMLHttpRequest',
    };

    async function remove(relationship: RelationshipRecord) {
        const confirmed = window.confirm(`Delete the "${relationship.name}" relationship?`);
        if (!confirmed) {
            return;
        }

        const response = await fetch(`/api/layers/${layer.id}/relationships/${relationship.id}`, {
            method: 'DELETE',
            headers,
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}) as Record<string, unknown>);
            window.alert(String(data.error || 'Could not delete the relationship.'));
            return;
        }
        router.reload({ only: ['relationships'] });
    }

    return (
        <AppLayout title={`Relationships — ${layer.name}`}>
            <PageHeader
                title="Relationships"
                action={
                    <div className="flex flex-wrap gap-2">
                        <GhostLink href={`/layers/${layer.id}`}>Back to layer</GhostLink>
                        {canEdit ? (
                            <PrimaryLink href={`/relationships?parent=${layer.id}`}>Add relationship</PrimaryLink>
                        ) : null}
                    </div>
                }
            />
            <Panel>
                <p className="max-w-prose text-sm text-muted-foreground">
                    Relate features of <span className="font-medium text-foreground">{layer.name}</span> (parent) to
                    features of another layer (child). Child rows are matched by a key column that stores the parent
                    feature&apos;s id, and appear in the inspector next to each feature. New connections are drawn in
                    the relationship builder.
                </p>

                <div className="mt-4">
                    {relationships.length === 0 ? (
                        <Empty>
                            No relationships yet. Add one in the relationship builder to show related records beside
                            each feature.
                        </Empty>
                    ) : (
                        <DataTable>
                            <Table.Header>
                                <Table.Row>
                                    <Table.Head className={thClass}>Name</Table.Head>
                                    <Table.Head className={thClass}>Related layer</Table.Head>
                                    <Table.Head className={thClass}>Key column</Table.Head>
                                    <Table.Head className={thClass}>Matches</Table.Head>
                                    {canEdit ? <Table.Head className={thClass}>Actions</Table.Head> : null}
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {relationships.map((relationship) => (
                                    <Table.Row key={relationship.id}>
                                        <Table.Cell className={tdClass}>{relationship.name}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            {relationship.related_layer?.name || '—'}
                                        </Table.Cell>
                                        <Table.Cell className={tdMono}>{relationship.foreign_key}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            {layer.name} id &rarr; {relationship.related_layer?.name || 'child'}{' '}
                                            {relationship.foreign_key}
                                        </Table.Cell>
                                        {canEdit ? (
                                            <Table.Cell className={tdClass}>
                                                <RowActions>
                                                    <DangerButton onClick={() => remove(relationship)}>
                                                        Delete
                                                    </DangerButton>
                                                </RowActions>
                                            </Table.Cell>
                                        ) : null}
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </DataTable>
                    )}
                </div>
            </Panel>
        </AppLayout>
    );
}
