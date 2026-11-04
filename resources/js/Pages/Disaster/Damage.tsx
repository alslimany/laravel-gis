import { Link, useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';
import { disasterTermPrefix, termLabel } from '@/lib/disaster-terms';

type DamagePointRow = {
    id: number;
    external_id?: string | null;
    damage_level: string;
    structure_type?: string | null;
    habitability?: string | null;
    urgent_needs?: string[] | null;
    people_affected?: number | null;
    review_status: string;
    reviewer_comments?: string | null;
    notes?: string | null;
    latitude?: number | null;
    longitude?: number | null;
    photo_count?: number;
    is_official?: boolean;
    zone?: string;
    review_url: string;
    incident_id?: number | null;
    lifeline_component_id?: number | null;
};

type AggregateSummary = {
    by_zone: Array<{ zone: string; total: number; people_affected: number; by_level: Record<string, number> }>;
    by_level: Record<string, number>;
    total: number;
    people_affected: number;
};

type Aggregates = {
    official: AggregateSummary;
    all: AggregateSummary;
};

type IncidentOption = { id: number; title: string; status: string };
type LifelineOption = { id: number; name: string; lifeline: string };
type ImageryRow = { id: number; incident_id: number; role: string; layer_id: number; label?: string | null };

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    points: DamagePointRow[];
    store_url: string;
    aggregates_url: string;
    damage_levels: string[];
    habitability_options: string[];
    urgent_need_options: string[];
    aggregates: Aggregates;
    form_schema_version?: string;
    incidents: IncidentOption[];
    lifeline_components: LifelineOption[];
    imagery: ImageryRow[];
    imagery_store_template: string;
    can_field: boolean;
    can_manage: boolean;
};

const URGENT_DEFAULT: string[] = [];

export default function Damage({
    advisory,
    authority = null,
    points,
    store_url,
    damage_levels,
    habitability_options,
    urgent_need_options,
    aggregates,
    form_schema_version = '1.0',
    incidents,
    lifeline_components,
    imagery,
    imagery_store_template,
    can_field,
    can_manage,
}: Props) {
    const { t } = useTranslation();
    const canEdit = can_field || can_manage;
    const form = useForm({
        damage_level: damage_levels[0] ?? 'minor',
        structure_type: '',
        habitability: '',
        urgent_needs: URGENT_DEFAULT as string[],
        people_affected: '' as string | number,
        external_id: '',
        notes: '',
        latitude: 32.88,
        longitude: 13.19,
        zone: '',
        incident_id: '' as string | number,
        lifeline_component_id: '' as string | number,
        form_schema_version,
        photos: [] as File[],
    });

    const imageryForm = useForm({
        incident_id: incidents[0]?.id ? String(incidents[0].id) : '',
        role: 'pre',
        layer_id: '',
        label: '',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            external_id: data.external_id || null,
            structure_type: data.structure_type || null,
            habitability: data.habitability || null,
            people_affected: data.people_affected === '' ? null : Number(data.people_affected),
            incident_id: data.incident_id === '' ? null : Number(data.incident_id),
            lifeline_component_id: data.lifeline_component_id === '' ? null : Number(data.lifeline_component_id),
            props: data.zone ? { zone: data.zone } : null,
        }));
        form.post(store_url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('external_id', 'notes', 'structure_type', 'habitability', 'urgent_needs', 'people_affected', 'zone', 'photos'),
        });
    }

    function toggleNeed(need: string) {
        const current = form.data.urgent_needs ?? [];
        form.setData(
            'urgent_needs',
            current.includes(need) ? current.filter((n) => n !== need) : [...current, need],
        );
    }

    function submitImagery(event: React.FormEvent) {
        event.preventDefault();
        if (!imageryForm.data.incident_id) {
            return;
        }
        const url = imagery_store_template.replace('{incident}', String(imageryForm.data.incident_id));
        imageryForm.post(url, {
            preserveScroll: true,
            onSuccess: () => imageryForm.reset('layer_id', 'label'),
        });
    }

    return (
        <AppLayout
            title={t('disaster.damage_reports')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.damage_reports') }]}
        >
            <PageHeader title={t('disaster.damage_assessment')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Panel>
                    <div className="p-3">
                        <div className="text-xs text-muted-foreground">{t('disaster.official_totals')}</div>
                        <div className="text-2xl font-semibold">{aggregates.official.total}</div>
                        <div className="text-xs text-muted-foreground">
                            {t('disaster.people_affected')}: {aggregates.official.people_affected}
                        </div>
                    </div>
                </Panel>
                <Panel>
                    <div className="p-3">
                        <div className="text-xs text-muted-foreground">{t('disaster.all_assessments')}</div>
                        <div className="text-2xl font-semibold">{aggregates.all.total}</div>
                        <div className="text-xs text-muted-foreground">
                            {t('disaster.unverified_excluded_hint')}
                        </div>
                    </div>
                </Panel>
                <Panel className="sm:col-span-2">
                    <div className="border-b border-border px-3 py-2 text-sm font-medium">{t('disaster.by_damage_level')}</div>
                    <ul className="flex flex-wrap gap-3 p-3 text-sm">
                        {Object.entries(aggregates.official.by_level).map(([level, count]) => (
                            <li key={level}>
                                <span className="font-medium">{termLabel(t, disasterTermPrefix.damageLevel, level)}</span>
                                <span className="ms-1 text-muted-foreground">{count}</span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2 text-sm text-muted-foreground">
                        {points.length === 1
                            ? t('disaster.field_damage_point', { count: points.length })
                            : t('disaster.field_damage_points', { count: points.length })}
                    </div>
                    {points.length === 0 ? (
                        <Empty>{t('disaster.empty.damage_reports')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {points.map((p) => {
                                const unverified = !p.is_official;
                                return (
                                    <li
                                        key={p.id}
                                        className={`flex flex-col gap-2 px-3 py-3 ${unverified ? 'bg-muted/40 opacity-80' : ''}`}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {termLabel(t, disasterTermPrefix.damageLevel, p.damage_level)}
                                            </span>
                                            <span className="text-muted-foreground">{p.external_id ?? `#${p.id}`}</span>
                                            <span
                                                className={`rounded px-1.5 py-0.5 text-xs ${
                                                    p.review_status === 'verified'
                                                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                                        : p.review_status === 'rejected'
                                                          ? 'bg-red-500/15 text-red-700 dark:text-red-300'
                                                          : 'bg-muted text-muted-foreground'
                                                }`}
                                            >
                                                {termLabel(t, 'disaster.review_status', p.review_status)}
                                            </span>
                                            {(p.photo_count ?? 0) > 0 ? (
                                                <span className="text-xs text-muted-foreground">
                                                    {t('disaster.photo_count', { count: p.photo_count })}
                                                </span>
                                            ) : null}
                                            {canEdit ? (
                                                <Button asChild variant="ghost" size="sm" className="ms-auto">
                                                    <Link
                                                        href={`/x/disaster/damage-points/${p.id}`}
                                                        method="delete"
                                                        as="button"
                                                        preserveScroll
                                                    >
                                                        {t('actions.delete')}
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </div>
                                        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                            {p.structure_type ? <span>{p.structure_type}</span> : null}
                                            {p.habitability ? <span>{termLabel(t, 'disaster.habitability', p.habitability)}</span> : null}
                                            {p.zone && p.zone !== 'unassigned' ? <span>{p.zone}</span> : null}
                                            {p.people_affected != null ? (
                                                <span>
                                                    {t('disaster.people_affected')}: {p.people_affected}
                                                </span>
                                            ) : null}
                                        </div>
                                        {can_manage && p.review_status === 'submitted' ? (
                                            <div className="flex flex-wrap gap-2">
                                                <Button asChild size="sm" variant="secondary">
                                                    <Link href={p.review_url} method="post" data={{ review_status: 'reviewed' }} as="button" preserveScroll>
                                                        {t('disaster.mark_reviewed')}
                                                    </Link>
                                                </Button>
                                            </div>
                                        ) : null}
                                        {can_manage && p.review_status === 'reviewed' ? (
                                            <div className="flex flex-wrap gap-2">
                                                <Button asChild size="sm">
                                                    <Link href={p.review_url} method="post" data={{ review_status: 'verified' }} as="button" preserveScroll>
                                                        {t('disaster.verify')}
                                                    </Link>
                                                </Button>
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={p.review_url} method="post" data={{ review_status: 'rejected' }} as="button" preserveScroll>
                                                        {t('disaster.reject')}
                                                    </Link>
                                                </Button>
                                            </div>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>

                {canEdit ? (
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.record_damage')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3" encType="multipart/form-data">
                            <Field label={t('disaster.damage_level')} error={form.errors.damage_level}>
                                <Select value={form.data.damage_level} onChange={(e) => form.setData('damage_level', e.target.value)}>
                                    {damage_levels.map((level) => (
                                        <option key={level} value={level}>
                                            {termLabel(t, disasterTermPrefix.damageLevel, level)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.structure_type')}>
                                <TextInput value={form.data.structure_type} onChange={(e) => form.setData('structure_type', e.target.value)} />
                            </Field>
                            <Field label={t('disaster.habitability')} error={form.errors.habitability}>
                                <Select value={form.data.habitability} onChange={(e) => form.setData('habitability', e.target.value)}>
                                    <option value="">{t('forms.optional')}</option>
                                    {habitability_options.map((opt) => (
                                        <option key={opt} value={opt}>
                                            {termLabel(t, 'disaster.habitability', opt)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">{t('disaster.urgent_needs')}</legend>
                                <div className="flex flex-wrap gap-3">
                                    {urgent_need_options.map((need) => (
                                        <label key={need} className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={form.data.urgent_needs.includes(need)}
                                                onChange={() => toggleNeed(need)}
                                            />
                                            {termLabel(t, 'disaster.urgent_need', need)}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <Field label={t('disaster.people_affected')} error={form.errors.people_affected}>
                                <TextInput
                                    type="number"
                                    min={0}
                                    value={String(form.data.people_affected)}
                                    onChange={(e) => form.setData('people_affected', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.zone_neighborhood')}>
                                <TextInput value={form.data.zone} onChange={(e) => form.setData('zone', e.target.value)} />
                            </Field>
                            <Field label={t('forms.external_id_optional')}>
                                <TextInput value={form.data.external_id} onChange={(e) => form.setData('external_id', e.target.value)} />
                            </Field>
                            <Field label={t('forms.notes')}>
                                <TextInput value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label={t('forms.latitude')} error={form.errors.latitude}>
                                    <TextInput type="number" step="0.0001" value={String(form.data.latitude)} onChange={(e) => form.setData('latitude', Number(e.target.value))} />
                                </Field>
                                <Field label={t('forms.longitude')} error={form.errors.longitude}>
                                    <TextInput type="number" step="0.0001" value={String(form.data.longitude)} onChange={(e) => form.setData('longitude', Number(e.target.value))} />
                                </Field>
                            </div>
                            {incidents.length > 0 ? (
                                <Field label={t('disaster.incident')}>
                                    <Select
                                        value={String(form.data.incident_id)}
                                        onChange={(e) => form.setData('incident_id', e.target.value)}
                                    >
                                        <option value="">{t('forms.optional')}</option>
                                        {incidents.map((incident) => (
                                            <option key={incident.id} value={incident.id}>
                                                {incident.title}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            ) : null}
                            {lifeline_components.length > 0 ? (
                                <Field label={t('disaster.lifeline_component')}>
                                    <Select
                                        value={String(form.data.lifeline_component_id)}
                                        onChange={(e) => form.setData('lifeline_component_id', e.target.value)}
                                    >
                                        <option value="">{t('forms.optional')}</option>
                                        {lifeline_components.map((component) => (
                                            <option key={component.id} value={component.id}>
                                                {component.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            ) : null}
                            <Field label={t('disaster.photos_max', { max: 5 })} error={form.errors.photos}>
                                <input
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    capture="environment"
                                    className="block w-full text-sm"
                                    onChange={(e) => form.setData('photos', Array.from(e.target.files ?? []).slice(0, 5))}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={form.processing}>
                                {t('disaster.save_damage_point')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : null}
            </div>

            {aggregates.official.by_zone.length > 0 ? (
                <Panel className="mt-6">
                    <div className="border-b border-border px-3 py-2 text-sm font-medium">{t('disaster.aggregates_by_zone')}</div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-start text-muted-foreground">
                                    <th className="px-3 py-2">{t('disaster.zone_neighborhood')}</th>
                                    <th className="px-3 py-2">{t('disaster.official_totals')}</th>
                                    <th className="px-3 py-2">{t('disaster.people_affected')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {aggregates.official.by_zone.map((row) => (
                                    <tr key={row.zone} className="border-b border-border/60">
                                        <td className="px-3 py-2">{row.zone}</td>
                                        <td className="px-3 py-2">{row.total}</td>
                                        <td className="px-3 py-2">{row.people_affected}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            ) : null}

            {can_manage ? (
                <Panel className="mt-6">
                    <div className="border-b border-border px-3 py-2 text-sm font-medium">{t('disaster.imagery_register')}</div>
                    <form onSubmit={submitImagery} className="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label={t('disaster.incident')}>
                            <Select value={imageryForm.data.incident_id} onChange={(e) => imageryForm.setData('incident_id', e.target.value)}>
                                <option value="">{t('forms.optional')}</option>
                                {incidents.map((incident) => (
                                    <option key={incident.id} value={incident.id}>
                                        {incident.title}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('disaster.imagery_role')}>
                            <Select value={imageryForm.data.role} onChange={(e) => imageryForm.setData('role', e.target.value)}>
                                <option value="pre">pre</option>
                                <option value="post">post</option>
                            </Select>
                        </Field>
                        <Field label={t('disaster.layer_id')} error={imageryForm.errors.layer_id}>
                            <TextInput
                                type="number"
                                value={imageryForm.data.layer_id}
                                onChange={(e) => imageryForm.setData('layer_id', e.target.value)}
                            />
                        </Field>
                        <Field label={t('forms.label_optional')}>
                            <TextInput value={imageryForm.data.label} onChange={(e) => imageryForm.setData('label', e.target.value)} />
                        </Field>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <PrimaryButton type="submit" disabled={imageryForm.processing || !imageryForm.data.incident_id}>
                                {t('disaster.register_imagery')}
                            </PrimaryButton>
                        </div>
                    </form>
                    {imagery.length > 0 ? (
                        <ul className="divide-y divide-border border-t border-border text-sm">
                            {imagery.map((row) => (
                                <li key={row.id} className="flex flex-wrap gap-2 px-3 py-2">
                                    <span className="font-medium">{enumLabel(row.role)}</span>
                                    <span className="text-muted-foreground">
                                        {t('disaster.layer_id')}: {row.layer_id}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {t('disaster.incident')} #{row.incident_id}
                                    </span>
                                    {row.label ? <span>{row.label}</span> : null}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </Panel>
            ) : null}
        </AppLayout>
    );
}
