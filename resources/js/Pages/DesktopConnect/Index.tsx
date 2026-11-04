import { FormEvent, useMemo } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { whenTime } from '@/lib/format';
import {
    DangerButton,
    Empty,
    Field,
    PageHeader,
    Panel,
    PrimaryButton,
    Select,
    Table,
    tdClass,
    thClass,
    DataTable,
} from '@/components/gis';

type LayerOption = {
    id: number;
    name: string;
    public_id: string | null;
    alias: string | null;
    writable: boolean;
};
type UserOption = { id: number; name: string; email: string };
type GroupOption = { id: number; name: string };

type GrantRow = {
    id: number;
    scope: string;
    expires_at: string | null;
    layer: {
        id: number;
        name: string;
        public_id: string | null;
        alias: string | null;
    } | null;
    user: { id: number; name: string; email: string } | null;
    group: { id: number; name: string } | null;
    granted_by: string | null;
    created_at: string | null;
    destroy_url: string;
};

type Props = {
    grants: GrantRow[];
    layers: LayerOption[];
    users: UserOption[];
    groups: GroupOption[];
    filters: { layer_id: number | null; user_id: number | null };
    store_url: string;
    index_url: string;
    can_manage?: boolean;
};

function layerLabel(layer: { name: string; alias: string | null }): string {
    return layer.alias ? `${layer.name} (${layer.alias})` : layer.name;
}

export default function Index({
    grants,
    layers,
    users,
    groups,
    filters,
    store_url,
    index_url,
    can_manage = false,
}: Props) {
    const { t } = useTranslation();

    const form = useForm({
        layer_id: layers[0]?.id?.toString() ?? '',
        scope: 'read',
        subject_type: 'user',
        user_id: users[0]?.id?.toString() ?? '',
        group_id: groups[0]?.id?.toString() ?? '',
        expires_at: '',
    });

    const selectedLayer = useMemo(
        () => layers.find((layer) => String(layer.id) === String(form.data.layer_id)),
        [layers, form.data.layer_id],
    );
    const layerWritable = selectedLayer?.writable !== false;
    const subjectReady =
        form.data.subject_type === 'user' ? Boolean(form.data.user_id) : Boolean(form.data.group_id);

    function applyFilter(key: 'layer_id' | 'user_id', value: string) {
        router.get(
            index_url,
            {
                layer_id: key === 'layer_id' ? value || undefined : filters.layer_id || undefined,
                user_id: key === 'user_id' ? value || undefined : filters.user_id || undefined,
            },
            { preserveState: true, replace: true },
        );
    }

    function setLayer(value: string) {
        const next = layers.find((layer) => String(layer.id) === value);
        form.setData((data) => ({
            ...data,
            layer_id: value,
            scope: next && !next.writable && data.scope === 'edit' ? 'read' : data.scope,
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            layer_id: Number(data.layer_id),
            scope: data.scope,
            user_id: data.subject_type === 'user' && data.user_id ? Number(data.user_id) : null,
            group_id: data.subject_type === 'group' && data.group_id ? Number(data.group_id) : null,
            expires_at: data.expires_at || null,
        }));
        form.post(store_url, {
            onSuccess: () => form.reset('expires_at'),
        });
    }

    function revoke(destroyUrl: string) {
        if (!confirm(t('desktop.grants.revoke_confirm'))) {
            return;
        }
        router.delete(destroyUrl);
    }

    return (
        <AppLayout title={t('desktop.title')} breadcrumbs={[{ title: t('desktop.title') }]}>
            <PageHeader title={t('desktop.title')} />

            <p className="mb-6 max-w-2xl text-sm text-muted-foreground">{t('desktop.grants.intro')}</p>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                {can_manage ? (
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('desktop.grants.create')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('desktop.grants.layer')}>
                                <Select value={form.data.layer_id} onChange={(e) => setLayer(e.target.value)}>
                                    {layers.map((layer) => (
                                        <option key={layer.id} value={layer.id}>
                                            {layerLabel(layer)}
                                            {!layer.writable ? ` (${t('desktop.grants.read_only')})` : ''}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field label={t('desktop.grants.scope')}>
                                <Select
                                    value={form.data.scope}
                                    onChange={(e) => form.setData('scope', e.target.value)}
                                >
                                    <option value="read">{t('desktop.grants.scope_read')}</option>
                                    <option value="edit" disabled={!layerWritable}>
                                        {t('desktop.grants.scope_edit')}
                                    </option>
                                </Select>
                            </Field>

                            <Field label={t('desktop.grants.subject')}>
                                <Select
                                    value={form.data.subject_type}
                                    onChange={(e) => form.setData('subject_type', e.target.value)}
                                >
                                    <option value="user">{t('desktop.grants.subject_user')}</option>
                                    <option value="group">{t('desktop.grants.subject_group')}</option>
                                </Select>
                            </Field>

                            {form.data.subject_type === 'user' ? (
                                <Field label={t('desktop.grants.user')}>
                                    <Select
                                        value={form.data.user_id}
                                        onChange={(e) => form.setData('user_id', e.target.value)}
                                    >
                                        {users.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.name} ({u.email})
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            ) : (
                                <Field label={t('desktop.grants.group')}>
                                    <Select
                                        value={form.data.group_id}
                                        onChange={(e) => form.setData('group_id', e.target.value)}
                                    >
                                        {groups.map((g) => (
                                            <option key={g.id} value={g.id}>
                                                {g.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            )}

                            <Field label={t('desktop.grants.expires_at')}>
                                <input
                                    type="datetime-local"
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                                    value={form.data.expires_at}
                                    onChange={(e) => form.setData('expires_at', e.target.value)}
                                />
                            </Field>

                            {form.errors.layer_id ||
                            form.errors.scope ||
                            form.errors.user_id ||
                            form.errors.group_id ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.layer_id ||
                                        form.errors.scope ||
                                        form.errors.user_id ||
                                        form.errors.group_id}
                                </p>
                            ) : null}

                            <PrimaryButton
                                type="submit"
                                disabled={
                                    form.processing || layers.length === 0 || !subjectReady
                                }
                            >
                                {t('desktop.grants.grant')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : null}

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('desktop.grants.filters')}</h2>
                    </div>
                    <div className="space-y-3 p-3">
                        <Field label={t('desktop.grants.layer')}>
                            <Select
                                value={filters.layer_id?.toString() ?? ''}
                                onChange={(e) => applyFilter('layer_id', e.target.value)}
                            >
                                <option value="">{t('desktop.grants.all_layers')}</option>
                                {layers.map((layer) => (
                                    <option key={layer.id} value={layer.id}>
                                        {layerLabel(layer)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('desktop.grants.user')}>
                            <Select
                                value={filters.user_id?.toString() ?? ''}
                                onChange={(e) => applyFilter('user_id', e.target.value)}
                            >
                                <option value="">{t('desktop.grants.all_users')}</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                </Panel>
            </div>

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('desktop.grants.list')}</h2>
                </div>
                {grants.length === 0 ? (
                    <Empty>{t('desktop.grants.empty')}</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('desktop.grants.layer')}</Table.Head>
                                <Table.Head className={thClass}>{t('desktop.grants.subject')}</Table.Head>
                                <Table.Head className={thClass}>{t('desktop.grants.scope')}</Table.Head>
                                <Table.Head className={thClass}>{t('desktop.grants.expires_at')}</Table.Head>
                                {can_manage ? (
                                    <Table.Head className={`${thClass} text-end`}>{t('forms.actions')}</Table.Head>
                                ) : null}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {grants.map((grant) => (
                                <Table.Row key={grant.id}>
                                    <Table.Cell className={tdClass}>
                                        {grant.layer ? (
                                            <span className="block">
                                                <span>{layerLabel(grant.layer)}</span>
                                                {grant.layer.public_id ? (
                                                    <span
                                                        dir="ltr"
                                                        className="mt-0.5 block font-mono text-xs text-muted-foreground"
                                                    >
                                                        {grant.layer.public_id}
                                                    </span>
                                                ) : null}
                                            </span>
                                        ) : (
                                            t('common.em_dash')
                                        )}
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        {grant.user
                                            ? grant.user.name
                                            : grant.group
                                              ? `${t('desktop.grants.subject_group')}: ${grant.group.name}`
                                              : t('common.em_dash')}
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        {grant.scope === 'edit'
                                            ? t('desktop.grants.scope_edit')
                                            : t('desktop.grants.scope_read')}
                                    </Table.Cell>
                                    <Table.Cell className={tdClass}>
                                        {grant.expires_at
                                            ? whenTime(grant.expires_at)
                                            : t('desktop.grants.never')}
                                    </Table.Cell>
                                    {can_manage ? (
                                        <Table.Cell className={`${tdClass} text-end`}>
                                            <DangerButton onClick={() => revoke(grant.destroy_url)}>
                                                {t('desktop.grants.revoke')}
                                            </DangerButton>
                                        </Table.Cell>
                                    ) : null}
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
            </Panel>
        </AppLayout>
    );
}
