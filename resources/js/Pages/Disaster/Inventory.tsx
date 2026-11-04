import { useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import AdvisoryBanner from './AdvisoryBanner';

type WarehouseRow = {
    id: number;
    name: string;
    status: string;
    manager_name?: string | null;
    latitude?: number | null;
    longitude?: number | null;
};

type ItemRow = {
    id: number;
    warehouse_name?: string | null;
    resource_type_name?: string | null;
    unit?: string | null;
    quantity: number;
    expiry?: string | null;
};

type LedgerRow = {
    id: number;
    warehouse_name?: string | null;
    resource_type_name?: string | null;
    delta: number;
    reason: string;
    actor_name?: string | null;
    note?: string | null;
    created_at?: string | null;
};

type Option = { value: string; label: string };

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    warehouses: WarehouseRow[];
    resource_types: { id: number; name: string; category?: string | null; unit?: string | null }[];
    items: ItemRow[];
    ledger: LedgerRow[];
    managers: { id: number; name: string }[];
    warehouse_status_options: Option[];
    ledger_reason_options: Option[];
    store_type_url: string;
    store_warehouse_url: string;
    adjust_url: string;
    can_manage: boolean;
};

export default function Inventory({
    advisory,
    authority = null,
    warehouses,
    resource_types,
    items,
    ledger,
    managers,
    warehouse_status_options,
    ledger_reason_options,
    store_type_url,
    store_warehouse_url,
    adjust_url,
    can_manage,
}: Props) {
    const { t } = useTranslation();

    const typeForm = useForm({
        name: '',
        category: '',
        capability: '',
        unit: 'ea',
    });

    const warehouseForm = useForm({
        name: '',
        manager_user_id: '',
        status: 'active',
        latitude: '32.88',
        longitude: '13.19',
    });

    const adjustForm = useForm({
        warehouse_id: warehouses[0]?.id ?? '',
        resource_type_id: resource_types[0]?.id ?? '',
        delta: 10,
        reason: 'in',
        note: '',
        expiry: '',
    });

    return (
        <AppLayout
            title={t('disaster.inventory')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.inventory_short') },
            ]}
        >
            <PageHeader title={t('disaster.inventory')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.warehouses')}</h2>
                    </div>
                    {warehouses.length === 0 ? (
                        <Empty>{t('disaster.empty.warehouses')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {warehouses.map((wh) => (
                                <li key={wh.id} className="px-3 py-2">
                                    <div className="font-medium">{wh.name}</div>
                                    <div className="text-muted-foreground">
                                        {enumLabel(wh.status)}
                                        {wh.manager_name ? ` · ${wh.manager_name}` : ''}
                                        {wh.latitude != null && wh.longitude != null
                                            ? ` · ${wh.latitude.toFixed(4)}, ${wh.longitude.toFixed(4)}`
                                            : ''}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.stock_levels')}</h2>
                    </div>
                    {items.length === 0 ? (
                        <Empty>{t('disaster.empty.inventory')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {items.map((item) => (
                                <li key={item.id} className="flex flex-wrap gap-2 px-3 py-2">
                                    <span className="font-medium">
                                        {item.quantity} {item.unit} {item.resource_type_name}
                                    </span>
                                    <span className="text-muted-foreground">@ {item.warehouse_name}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            <Panel className="mb-6">
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.ledger')}</h2>
                </div>
                {ledger.length === 0 ? (
                    <Empty>{t('disaster.empty.ledger')}</Empty>
                ) : (
                    <ul className="divide-y divide-border text-sm">
                        {ledger.map((entry) => (
                            <li key={entry.id} className="px-3 py-2">
                                <span className="font-medium">
                                    {entry.delta > 0 ? '+' : ''}
                                    {entry.delta}
                                </span>{' '}
                                {entry.resource_type_name} @ {entry.warehouse_name} · {enumLabel(entry.reason)}
                                {entry.actor_name ? ` · ${entry.actor_name}` : ''}
                                {entry.note ? ` — ${entry.note}` : ''}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {can_manage ? (
                <div className="grid gap-6 lg:grid-cols-3">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.add_resource_type')}</h2>
                        </div>
                        <form
                            className="space-y-3 p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                typeForm.post(store_type_url, {
                                    preserveScroll: true,
                                    onSuccess: () => typeForm.reset('name', 'category', 'capability'),
                                });
                            }}
                        >
                            <Field label={t('forms.name')} error={typeForm.errors.name}>
                                <TextInput
                                    value={typeForm.data.name}
                                    required
                                    onChange={(e) => typeForm.setData('name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.category')} error={typeForm.errors.category}>
                                <TextInput
                                    value={typeForm.data.category}
                                    onChange={(e) => typeForm.setData('category', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.capability')} error={typeForm.errors.capability}>
                                <TextInput
                                    value={typeForm.data.capability}
                                    onChange={(e) => typeForm.setData('capability', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.unit')} error={typeForm.errors.unit}>
                                <TextInput
                                    value={typeForm.data.unit}
                                    onChange={(e) => typeForm.setData('unit', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={typeForm.processing}>
                                {t('disaster.add_resource_type')}
                            </PrimaryButton>
                        </form>
                    </Panel>

                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.add_warehouse')}</h2>
                        </div>
                        <form
                            className="space-y-3 p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                warehouseForm.transform((data) => ({
                                    ...data,
                                    manager_user_id: data.manager_user_id === '' ? null : Number(data.manager_user_id),
                                    latitude: data.latitude === '' ? null : Number(data.latitude),
                                    longitude: data.longitude === '' ? null : Number(data.longitude),
                                })).post(store_warehouse_url, {
                                    preserveScroll: true,
                                    onSuccess: () => warehouseForm.reset('name'),
                                });
                            }}
                        >
                            <Field label={t('forms.name')} error={warehouseForm.errors.name}>
                                <TextInput
                                    value={warehouseForm.data.name}
                                    required
                                    onChange={(e) => warehouseForm.setData('name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.manager')} error={warehouseForm.errors.manager_user_id}>
                                <Select
                                    value={String(warehouseForm.data.manager_user_id)}
                                    onChange={(e) => warehouseForm.setData('manager_user_id', e.target.value)}
                                >
                                    <option value="">{t('disaster.unassigned')}</option>
                                    {managers.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('forms.status')}>
                                <Select
                                    value={warehouseForm.data.status}
                                    onChange={(e) => warehouseForm.setData('status', e.target.value)}
                                >
                                    {warehouse_status_options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('forms.latitude')} error={warehouseForm.errors.latitude}>
                                <TextInput
                                    value={warehouseForm.data.latitude}
                                    onChange={(e) => warehouseForm.setData('latitude', e.target.value)}
                                />
                            </Field>
                            <Field label={t('forms.longitude')} error={warehouseForm.errors.longitude}>
                                <TextInput
                                    value={warehouseForm.data.longitude}
                                    onChange={(e) => warehouseForm.setData('longitude', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={warehouseForm.processing}>
                                {t('disaster.add_warehouse')}
                            </PrimaryButton>
                        </form>
                    </Panel>

                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.adjust_stock')}</h2>
                        </div>
                        <form
                            className="space-y-3 p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                adjustForm.post(adjust_url, { preserveScroll: true });
                            }}
                        >
                            <Field label={t('disaster.warehouse')} error={adjustForm.errors.warehouse_id}>
                                <Select
                                    value={String(adjustForm.data.warehouse_id)}
                                    onChange={(e) => adjustForm.setData('warehouse_id', Number(e.target.value))}
                                    required
                                >
                                    {warehouses.map((wh) => (
                                        <option key={wh.id} value={wh.id}>
                                            {wh.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.resource_type')} error={adjustForm.errors.resource_type_id}>
                                <Select
                                    value={String(adjustForm.data.resource_type_id)}
                                    onChange={(e) => adjustForm.setData('resource_type_id', Number(e.target.value))}
                                    required
                                >
                                    {resource_types.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.delta')} error={adjustForm.errors.delta}>
                                <TextInput
                                    type="number"
                                    value={adjustForm.data.delta}
                                    onChange={(e) => adjustForm.setData('delta', Number(e.target.value))}
                                    required
                                />
                            </Field>
                            <Field label={t('disaster.ledger_reason')}>
                                <Select
                                    value={adjustForm.data.reason}
                                    onChange={(e) => adjustForm.setData('reason', e.target.value)}
                                >
                                    {ledger_reason_options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('forms.notes')} error={adjustForm.errors.note}>
                                <TextInput
                                    value={adjustForm.data.note}
                                    onChange={(e) => adjustForm.setData('note', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton
                                type="submit"
                                disabled={
                                    adjustForm.processing || warehouses.length === 0 || resource_types.length === 0
                                }
                            >
                                {t('disaster.adjust_stock')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">{t('disaster.manage_required_inventory')}</p>
            )}
        </AppLayout>
    );
}
