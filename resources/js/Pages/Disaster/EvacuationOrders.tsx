import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import ZoneDrawMap, { type ZonePolygon } from './ZoneDrawMap';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import AdvisoryBanner from './AdvisoryBanner';

type OrderRow = {
    id: number;
    status: string;
    text_en?: string | null;
    text_ar?: string | null;
    is_official: boolean;
    estimated_population?: number | null;
    evacuated_count: number;
    show_url: string;
};

type Option = { id: number; name: string; site_id?: string; capacity?: number | null; remaining_capacity?: number | null };
type ActivationOption = { id: number; hazard_type: string; source_alert_id: string; status: string };

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    orders: OrderRow[];
    corridors: Option[];
    shelters: Option[];
    activations: ActivationOption[];
    store_url: string;
    can_manage: boolean;
    can_dispatch: boolean;
};

export default function EvacuationOrders({
    advisory,
    authority = null,
    orders,
    corridors,
    shelters,
    activations,
    store_url,
    can_manage,
    can_dispatch,
}: Props) {
    const { t, locale } = useTranslation();
    const canWrite = can_manage || can_dispatch;
    const form = useForm({
        text_en: '',
        text_ar: '',
        activation_id: '',
        estimated_population: '',
        is_official: false,
        corridor_ids: [] as string[],
        shelter_site_ids: [] as string[],
        geojson: null as ZonePolygon,
    });

    // /x/disaster/evacuation-orders/create redirects here with ?new=1: bring the create form into view and focus it.
    const createPanelRef = useRef<HTMLDivElement | null>(null);
    const arabicTextRef = useRef<HTMLTextAreaElement | null>(null);
    useEffect(() => {
        if (!canWrite || typeof window === 'undefined') {
            return;
        }
        if (new URLSearchParams(window.location.search).get('new') === '1') {
            createPanelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            arabicTextRef.current?.focus({ preventScroll: true });
        }
    }, [canWrite]);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            activation_id: data.activation_id === '' ? null : Number(data.activation_id),
            estimated_population: data.estimated_population === '' ? null : Number(data.estimated_population),
            corridor_ids: data.corridor_ids.map(Number),
            shelter_site_ids: data.shelter_site_ids.map(Number),
        }));
        form.post(store_url, {
            preserveScroll: true,
            onSuccess: () => form.reset('text_en', 'text_ar', 'estimated_population', 'geojson'),
        });
    }

    function toggleMulti(key: 'corridor_ids' | 'shelter_site_ids', value: string) {
        const current = form.data[key];
        form.setData(key, current.includes(value) ? current.filter((v) => v !== value) : [...current, value]);
    }

    return (
        <AppLayout
            title={t('disaster.evac_orders_short')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.evac_orders_short') },
            ]}
        >
            <PageHeader title={t('disaster.evac_orders')} />
            {advisory ? (
                <AdvisoryBanner
                    advisory={advisory}
                    authority={authority}
                    className="mb-6 max-w-2xl text-sm text-muted-foreground"
                />
            ) : null}

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.evac_orders_list')}</h2>
                    </div>
                    {orders.length === 0 ? (
                        <Empty>{t('disaster.empty.evac_orders')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {orders.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <Link href={row.show_url} className="font-medium text-primary hover:underline">
                                        #{row.id}
                                    </Link>
                                    <Badge variant="secondary">{enumLabel(row.status)}</Badge>
                                    {row.is_official ? (
                                        <Badge variant="outline">{t('disaster.official')}</Badge>
                                    ) : null}
                                    <span className="truncate text-muted-foreground">
                                        {(locale === 'ar' ? row.text_ar || row.text_en : row.text_en || row.text_ar) ?? ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                {canWrite ? (
                    <div ref={createPanelRef} id="new-evacuation-order" className="scroll-mt-4">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.create_evac_order')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('disaster.order_text_ar')} error={form.errors.text_ar}>
                                <textarea
                                    className="min-h-[5rem] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    ref={arabicTextRef}
                                    value={form.data.text_ar}
                                    dir="rtl"
                                    onChange={(e) => form.setData('text_ar', e.target.value)}
                                />
                            </Field>
                            <details className="rounded-md border border-border px-3 py-2" open={form.data.text_en !== '' || Boolean(form.errors.text_en)}>
                                <summary className="cursor-pointer text-sm text-muted-foreground">
                                    {t('disaster.evac.add_english')}
                                </summary>
                                <div className="pt-2">
                                    <Field label={t('disaster.order_text_en')} error={form.errors.text_en}>
                                        <textarea
                                            className="min-h-[4rem] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            value={form.data.text_en}
                                            dir="ltr"
                                            onChange={(e) => form.setData('text_en', e.target.value)}
                                        />
                                    </Field>
                                </div>
                            </details>
                            <Field label={t('disaster.activation')}>
                                <Select
                                    value={form.data.activation_id}
                                    onChange={(e) => form.setData('activation_id', e.target.value)}
                                >
                                    <option value="">{t('common.none')}</option>
                                    {activations.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            #<bdi>{a.id}</bdi> {enumLabel(a.hazard_type)} (<bdi>{a.source_alert_id}</bdi>)
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.estimated_population')} error={form.errors.estimated_population}>
                                <TextInput
                                    type="number"
                                    min={0}
                                    value={form.data.estimated_population}
                                    onChange={(e) => form.setData('estimated_population', e.target.value)}
                                />
                            </Field>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_official}
                                    onChange={(e) => form.setData('is_official', e.target.checked)}
                                />
                                {t('disaster.mark_official')}
                            </label>
                            <Field label={t('disaster.corridors')}>
                                <div className="max-h-28 space-y-1 overflow-y-auto rounded-md border border-input p-2 text-sm">
                                    {corridors.length === 0 ? (
                                        <p className="text-muted-foreground">{t('disaster.empty.corridors')}</p>
                                    ) : (
                                        corridors.map((c) => (
                                            <label key={c.id} className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.corridor_ids.includes(String(c.id))}
                                                    onChange={() => toggleMulti('corridor_ids', String(c.id))}
                                                />
                                                {c.name}
                                            </label>
                                        ))
                                    )}
                                </div>
                            </Field>
                            <Field label={t('disaster.shelters')}>
                                <div className="max-h-28 space-y-1 overflow-y-auto rounded-md border border-input p-2 text-sm">
                                    {shelters.length === 0 ? (
                                        <p className="text-muted-foreground">{t('disaster.empty.critical_sites')}</p>
                                    ) : (
                                        shelters.map((s) => (
                                            <label key={s.id} className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.shelter_site_ids.includes(String(s.id))}
                                                    onChange={() => toggleMulti('shelter_site_ids', String(s.id))}
                                                />
                                                {s.name}
                                                {s.capacity != null ? ` (${s.capacity})` : ''}
                                            </label>
                                        ))
                                    )}
                                </div>
                            </Field>
                            <Field label={t('disaster.geojson_polygon')} error={form.errors.geojson}>
                                <ZoneDrawMap value={form.data.geojson} onChange={(zone) => form.setData('geojson', zone)} />
                            </Field>
                            <PrimaryButton type="submit" disabled={form.processing}>
                                {t('disaster.create_evac_order')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
