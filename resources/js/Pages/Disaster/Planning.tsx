import { Link, useForm, router } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';

type Corridor = {
    id: number;
    name: string;
    shelter_site_id?: number | null;
    source?: string | null;
    distance_m?: number | null;
    travel_time_s?: number | null;
};
type Shelter = { id: number; name: string; site_id: string };
type Assignment = {
    id: number;
    zone_key: string;
    population: number;
    override_reason?: string | null;
    shelter_site?: { id: number; name: string; site_id: string; capacity?: number | null } | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    corridors: Corridor[];
    shelters: Shelter[];
    assignments?: Assignment[];
    evac_store_url: string;
    routing_compute_url?: string;
    can_manage: boolean;
};

export default function Planning({
    advisory,
    authority = null,
    corridors,
    shelters,
    assignments = [],
    evac_store_url,
    routing_compute_url,
    can_manage,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        name: '',
        shelter_site_id: '',
        geojson_text: '{"type":"LineString","coordinates":[[13.19,32.88],[13.20,32.89]]}',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            shelter_site_id: data.shelter_site_id === '' ? null : Number(data.shelter_site_id),
        }));
        form.post(evac_store_url, { preserveScroll: true, onSuccess: () => form.reset('name') });
    }

    function recomputeRoutes() {
        if (!routing_compute_url) {
            return;
        }
        router.post(routing_compute_url, { sync: true }, { preserveScroll: true });
    }

    return (
        <AppLayout
            title={t('disaster.planning_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.planning_short') }]}
        >
            <PageHeader title={t('disaster.planning')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="flex items-center gap-2 border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.corridors')}</h2>
                        {can_manage && routing_compute_url ? (
                            <Button type="button" variant="outline" size="sm" className="ms-auto" onClick={recomputeRoutes}>
                                {__('disaster.recompute_routes')}
                            </Button>
                        ) : null}
                    </div>
                    {corridors.length === 0 ? (
                        <Empty>{t('disaster.empty.corridors')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {corridors.map((c) => (
                                <li key={c.id} className="flex items-center gap-2 px-3 py-2">
                                    <span className="font-medium">{c.name}</span>
                                    {c.source ? (
                                        <span className="text-xs text-muted-foreground">({enumLabel(c.source)})</span>
                                    ) : null}
                                    {can_manage ? (
                                        <Button asChild variant="ghost" size="sm" className="ms-auto">
                                            <Link
                                                href={`/x/disaster/evac-corridors/${c.id}`}
                                                method="delete"
                                                as="button"
                                                preserveScroll
                                            >
                                                {t('actions.delete')}
                                            </Link>
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                {can_manage ? (
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.add_corridor')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('forms.name')} error={form.errors.name}>
                                <TextInput value={form.data.name} required onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label={t('disaster.shelter_site')}>
                                <Select
                                    value={form.data.shelter_site_id}
                                    onChange={(e) => form.setData('shelter_site_id', e.target.value)}
                                >
                                    <option value="">{t('common.none')}</option>
                                    {shelters.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.site_id})
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.geojson_linestring')} error={form.errors.geojson_text}>
                                <textarea
                                    className="min-h-[8rem] w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                                    value={form.data.geojson_text}
                                    onChange={(e) => form.setData('geojson_text', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={form.processing}>
                                {t('disaster.create_corridor')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : null}

                <Panel className="lg:col-span-2">
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{__('disaster.shelter_assignments')}</h2>
                    </div>
                    {assignments.length === 0 ? (
                        <Empty>{__('disaster.no_shelter_assignments')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {assignments.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <span className="font-medium">{a.zone_key}</span>
                                    <span className="text-muted-foreground">→</span>
                                    <span>{a.shelter_site?.name ?? a.shelter_site?.site_id ?? '—'}</span>
                                    <span className="text-muted-foreground">pop {a.population}</span>
                                    {a.override_reason ? (
                                        <span className="text-xs text-amber-700 dark:text-amber-400">override</span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
