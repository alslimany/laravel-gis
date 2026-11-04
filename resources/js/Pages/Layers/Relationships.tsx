import { router, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
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
    const { t } = useTranslation();
    const { abilities } = usePage().props as { abilities?: { edit?: boolean } };
    const canEdit = Boolean(abilities?.edit);

    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'X-Requested-With': 'XMLHttpRequest',
    };

    async function remove(relationship: RelationshipRecord) {
        const confirmed = window.confirm(__('rel.confirm_delete', { name: relationship.name }));
        if (!confirmed) {
            return;
        }

        const response = await fetch(`/api/layers/${layer.id}/relationships/${relationship.id}`, {
            method: 'DELETE',
            headers,
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}) as Record<string, unknown>);
            window.alert(String(data.error || __('rel.delete_failed')));
            return;
        }
        router.reload({ only: ['relationships'] });
    }

    return (
        <AppLayout title={__('rel.title', { name: layer.name })}>
            <PageHeader
                title={t('relationships.title')}
                action={
                    <div className="flex flex-wrap gap-2">
                        <GhostLink href={`/layers/${layer.id}`}>{t('layers.back_layer')}</GhostLink>
                        {canEdit ? (
                            <PrimaryLink href={`/relationships?parent=${layer.id}`}>{t('layers.add_relationship')}</PrimaryLink>
                        ) : null}
                    </div>
                }
            />
            <Panel>
                <p className="max-w-prose text-sm text-muted-foreground">
                    {__('rel.intro_a')} <span className="font-medium text-foreground">{layer.name}</span> {__('rel.intro_b')}
                </p>

                <div className="mt-4">
                    {relationships.length === 0 ? (
                        <Empty>
                            {__('rel.empty')}
                        </Empty>
                    ) : (
                        <DataTable>
                            <Table.Header>
                                <Table.Row>
                                    <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                    <Table.Head className={thClass}>{__('rel.related_layer')}</Table.Head>
                                    <Table.Head className={thClass}>{t('common.key_column')}</Table.Head>
                                    <Table.Head className={thClass}>{__('rel.matches')}</Table.Head>
                                    {canEdit ? <Table.Head className={thClass}>{t('forms.actions')}</Table.Head> : null}
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {relationships.map((relationship) => (
                                    <Table.Row key={relationship.id}>
                                        <Table.Cell className={tdClass}>{relationship.name}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            {relationship.related_layer?.name || t('common.em_dash')}
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
                                                        {__('rel.delete')}
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
