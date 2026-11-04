import { useMemo, useState } from 'react';
import { enumLabel } from '@/lib/enum-labels';
import { Link, useForm, usePoll } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { when, whenTime } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import AdvisoryBanner from './AdvisoryBanner';
import DisasterMap, { type DisasterMapConfig } from './DisasterMap';

type SourceMeta = {
    fetched_at: string;
    stale_after_seconds: number;
    count: number;
};

type LifelineComponentRow = {
    id: number;
    name: string;
    status: string;
    color: string;
    reason?: string | null;
    reported_at?: string | null;
};

type LifelineRow = {
    lifeline: string;
    label: string;
    status: string;
    color: string;
    component_count: number;
    components: LifelineComponentRow[];
};

type EnumOption = { value: string; label: string };

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    generated_at: string;
    poll_interval_ms: number;
    stale_after_seconds: number;
    sources: Record<string, SourceMeta>;
    incidents: Array<{ id: number; external_id: string; title: string; priority?: number; opened_at?: string }>;
    closures: Array<{ id: number; external_id: string; closure_type?: string; severity?: string }>;
    activations: Array<{ id: number; hazard_type: string; severity?: string; status: string }>;
    evacuation_orders: Array<{ id: number; title: string; status: string; issued_at?: string }>;
    assets: {
        medical: Array<{ id: number; name: string; resource_status?: string }>;
        staging: Array<{ id: number; name: string; resource_status?: string }>;
        critical: Array<{ id: number; name: string; type?: string }>;
    };
    avl: {
        units: Array<{ id: number; unit_id: string; resource_class?: string; resource_status?: string }>;
        by_class: Record<string, number>;
        by_status: Record<string, number>;
    } | null;
    avl_included: boolean;
    lifelines: LifelineRow[];
    map: { points: Array<{ kind: string; id: number; label: string; latitude: number; longitude: number }> };
    org_map?: DisasterMapConfig | null;
    export_url: string;
    component_store_url: string;
    status_store_url: string;
    lifeline_options: EnumOption[];
    status_options: EnumOption[];
    can_manage: boolean;
    can_field: boolean;
    can_telemetry: boolean;
};

const colorClass: Record<string, string> = {
    green: 'bg-emerald-600',
    amber: 'bg-amber-500',
    red: 'bg-red-600',
    gray: 'bg-zinc-400',
};

function isStale(meta: SourceMeta | undefined, nowMs: number): boolean {
    if (!meta?.fetched_at) {
        return true;
    }
    const fetched = Date.parse(meta.fetched_at);
    if (Number.isNaN(fetched)) {
        return true;
    }
    return nowMs - fetched > (meta.stale_after_seconds ?? 30) * 1000;
}

export default function Cop({
    advisory,
    authority = null,
    generated_at,
    poll_interval_ms,
    sources,
    incidents,
    closures,
    activations,
    evacuation_orders,
    assets,
    avl,
    avl_included,
    lifelines,
    map,
    org_map,
    export_url,
    component_store_url,
    status_store_url,
    lifeline_options,
    status_options,
    can_manage,
    can_field,
}: Props) {
    const { t } = useTranslation();
    const [clock, setClock] = useState(() => Date.now());

    usePoll(poll_interval_ms || 15_000, {
        only: [
            'generated_at',
            'sources',
            'incidents',
            'closures',
            'activations',
            'evacuation_orders',
            'assets',
            'avl',
            'avl_included',
            'lifelines',
            'map',
        ],
        onFinish: () => setClock(Date.now()),
    }, { mode: 'rest' });

    const componentForm = useForm({
        lifeline: lifeline_options[0]?.value ?? 'safety_and_security',
        name: '',
        initial_status: 'unknown',
        reason: '',
    });

    const statusForm = useForm({
        component_id: '',
        status: 'at_risk',
        reason: '',
    });

    const allComponents = useMemo(
        () => lifelines.flatMap((row) => row.components.map((c) => ({ ...c, lifeline: row.label }))),
        [lifelines],
    );

    function submitComponent(event: React.FormEvent) {
        event.preventDefault();
        componentForm.post(component_store_url, {
            preserveScroll: true,
            onSuccess: () => componentForm.reset('name', 'reason'),
        });
    }

    function submitStatus(event: React.FormEvent) {
        event.preventDefault();
        statusForm.transform((data) => ({
            ...data,
            component_id: data.component_id === '' ? null : Number(data.component_id),
        })).post(status_store_url, {
            preserveScroll: true,
            onSuccess: () => statusForm.reset('reason'),
        });
    }

    return (
        <AppLayout
            title={t('disaster.cop_short')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.cop_short') },
            ]}
        >
            <PageHeader
                title={t('disaster.cop_page')}
                action={
                    <div className="flex flex-wrap gap-2">
                        <a
                            href={export_url}
                            className="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-xs hover:bg-primary/90"
                        >
                            {t('disaster.cop_export')}
                        </a>
                    </div>
                }
            />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-4 max-w-3xl text-sm text-muted-foreground" />

            <DisasterMap config={org_map} className="mb-4" />

            <div className="mb-4 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <span>
                    {t('disaster.cop_generated')}: {when(generated_at)}
                </span>
                <span>
                    {t('disaster.cop_poll')}: {Math.round((poll_interval_ms || 15000) / 1000)}s
                </span>
                {Object.entries(sources).map(([key, meta]) => (
                    <span key={key} className="inline-flex items-center gap-1">
                        <span
                            className={`inline-block size-2 rounded-full ${isStale(meta, clock) ? 'bg-amber-500' : 'bg-emerald-600'}`}
                            title={whenTime(meta.fetched_at)}
                        />
                        {enumLabel(key, 'disaster.cop_source')} (<bdi>{meta.count}</bdi>)
                    </span>
                ))}
            </div>

            <section className="mb-6">
                <h2 className="mb-2 text-sm font-semibold">{t('disaster.lifelines')}</h2>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {lifelines.map((row) => (
                        <div key={row.lifeline} className="rounded-md border border-border px-3 py-2">
                            <div className="flex items-start gap-2">
                                <span className={`mt-1 size-2.5 shrink-0 rounded-sm ${colorClass[row.color] ?? colorClass.gray}`} />
                                <div className="min-w-0">
                                    <div className="text-sm font-medium leading-tight">{row.label}</div>
                                    <div className="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                        <Badge variant="secondary">{enumLabel(row.status)}</Badge>
                                        <span>
                                            {row.component_count} {t('disaster.components')}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.open_incidents')}</h2>
                    </div>
                    {incidents.length === 0 ? (
                        <Empty>{t('disaster.empty.incidents')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {incidents.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <Link href={`/x/disaster/incidents/${row.id}`} className="font-medium text-primary hover:underline">
                                        {row.title}
                                    </Link>
                                    <span className="text-xs text-muted-foreground">{row.external_id}</span>
                                    {row.priority != null ? <Badge variant="outline">P{row.priority}</Badge> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.closures')}</h2>
                    </div>
                    {closures.length === 0 ? (
                        <Empty>{t('disaster.empty.closures')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {closures.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <span className="font-medium">{row.external_id}</span>
                                    <span className="text-muted-foreground">{enumLabel(row.closure_type)}</span>
                                    {row.severity ? <Badge variant="outline">{enumLabel(row.severity)}</Badge> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.activations_short')}</h2>
                    </div>
                    {activations.length === 0 ? (
                        <Empty>{t('disaster.empty.activations')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {activations.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <span className="font-medium">{enumLabel(row.hazard_type)}</span>
                                    <Badge variant="secondary">{enumLabel(row.status)}</Badge>
                                    {row.severity ? <Badge variant="outline">{enumLabel(row.severity)}</Badge> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.evacuation_orders_short')}</h2>
                    </div>
                    {evacuation_orders.length === 0 ? (
                        <Empty>{t('disaster.empty.evac_orders')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {evacuation_orders.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <Link
                                        href={`/x/disaster/evacuation-orders/${row.id}`}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {row.title}
                                    </Link>
                                    <Badge variant="secondary">{enumLabel(row.status)}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.fixed_assets')}</h2>
                    </div>
                    <div className="space-y-3 p-3 text-sm">
                        <AssetCount label={t('disaster.medical_facilities')} count={assets.medical.length} />
                        <AssetCount label={t('disaster.staging_areas')} count={assets.staging.length} />
                        <AssetCount label={t('disaster.critical_sites')} count={assets.critical.length} />
                    </div>
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.avl_short')}</h2>
                    </div>
                    {!avl_included || !avl ? (
                        <Empty>{t('disaster.avl_restricted')}</Empty>
                    ) : avl.units.length === 0 ? (
                        <Empty>{t('disaster.empty.avl_units')}</Empty>
                    ) : (
                        <div className="space-y-2 p-3 text-sm">
                            <div>
                                {t('disaster.avl_units')}: {avl.units.length}
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {Object.entries(avl.by_class).map(([key, count]) => (
                                    <Badge key={key} variant="outline">
                                        {enumLabel(key)}: <bdi>{count}</bdi>
                                    </Badge>
                                ))}
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {Object.entries(avl.by_status).map(([key, count]) => (
                                    <Badge key={key} variant="secondary">
                                        {enumLabel(key)}: <bdi>{count}</bdi>
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}
                </Panel>
            </div>

            <Panel className="mb-6">
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.cop_map_panel')}</h2>
                </div>
                {map.points.length === 0 ? (
                    <Empty>{t('disaster.empty.map_points')}</Empty>
                ) : (
                    <ul className="max-h-48 divide-y divide-border overflow-auto text-sm">
                        {map.points.slice(0, 40).map((point) => (
                            <li key={`${point.kind}-${point.id}`} className="flex flex-wrap gap-2 px-3 py-1.5">
                                <Badge variant="outline">{enumLabel(point.kind)}</Badge>
                                <span className="font-medium">{point.label}</span>
                                <span className="ms-auto text-xs text-muted-foreground">
                                    {point.latitude.toFixed(4)}, {point.longitude.toFixed(4)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {(can_manage || can_field) && (
                <div className="grid gap-6 lg:grid-cols-2">
                    {can_manage ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.add_lifeline_component')}</h2>
                            </div>
                            <form onSubmit={submitComponent} className="space-y-3 p-3">
                                <Field label={t('disaster.lifeline')} error={componentForm.errors.lifeline}>
                                    <Select
                                        value={componentForm.data.lifeline}
                                        onChange={(e) => componentForm.setData('lifeline', e.target.value)}
                                    >
                                        {lifeline_options.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('forms.name')} error={componentForm.errors.name}>
                                    <TextInput
                                        value={componentForm.data.name}
                                        onChange={(e) => componentForm.setData('name', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('disaster.status')} error={componentForm.errors.initial_status}>
                                    <Select
                                        value={componentForm.data.initial_status}
                                        onChange={(e) => componentForm.setData('initial_status', e.target.value)}
                                    >
                                        {status_options.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <PrimaryButton type="submit" disabled={componentForm.processing}>
                                    {t('disaster.add_lifeline_component')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}

                    {can_field ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.report_lifeline_status')}</h2>
                            </div>
                            <form onSubmit={submitStatus} className="space-y-3 p-3">
                                <Field label={t('disaster.component')} error={statusForm.errors.component_id}>
                                    <Select
                                        value={String(statusForm.data.component_id)}
                                        onChange={(e) => statusForm.setData('component_id', e.target.value)}
                                    >
                                        <option value="">{t('common.select')}</option>
                                        {allComponents.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {enumLabel(c.lifeline)}: {c.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('disaster.status')} error={statusForm.errors.status}>
                                    <Select
                                        value={statusForm.data.status}
                                        onChange={(e) => statusForm.setData('status', e.target.value)}
                                    >
                                        {status_options.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('disaster.reason')} error={statusForm.errors.reason}>
                                    <TextInput
                                        value={statusForm.data.reason}
                                        onChange={(e) => statusForm.setData('reason', e.target.value)}
                                    />
                                </Field>
                                <PrimaryButton type="submit" disabled={statusForm.processing || allComponents.length === 0}>
                                    {t('disaster.report_lifeline_status')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}
                </div>
            )}
        </AppLayout>
    );
}

function AssetCount({ label, count }: { label: string; count: number }) {
    return (
        <div className="flex items-center justify-between gap-2">
            <span>{label}</span>
            <Badge variant="secondary">{count}</Badge>
        </div>
    );
}
