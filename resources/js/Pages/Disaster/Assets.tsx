import { Link, useForm } from '@inertiajs/react';
import { fieldLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';
import DisasterMap, { type DisasterMapConfig } from './DisasterMap';
import { disasterTermPrefix, termLabel } from '@/lib/disaster-terms';

type MedicalRow = {
    id: number;
    facility_id: string;
    name: string;
    bed_capacity?: number | null;
    available_beds?: number | null;
    latitude?: number | null;
    longitude?: number | null;
};

type StagingRow = {
    id: number;
    staging_id: string;
    name: string;
    max_vehicle_cap?: number | null;
    latitude?: number | null;
    longitude?: number | null;
};

type CriticalRow = {
    id: number;
    site_id: string;
    type: string;
    name: string;
    capacity_mcm?: number | null;
    height_m?: number | null;
    year_built?: number | null;
    downstream_zone_layer_id?: number | null;
    latitude?: number | null;
    longitude?: number | null;
};

type OverdueRow = {
    id: number;
    site_id: number;
    condition_grade: string;
    next_inspection_due?: string | null;
    inspector?: string | null;
    maintenance_status?: string | null;
    site?: { id: number; site_id: string; name: string; type: string } | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    medical: MedicalRow[];
    staging: StagingRow[];
    critical: CriticalRow[];
    overdue_inspections?: OverdueRow[];
    medical_store_url: string;
    staging_store_url: string;
    critical_store_url: string;
    condition_store_url?: string;
    generator_statuses: string[];
    resource_statuses: string[];
    critical_site_types: string[];
    condition_grades?: string[];
    can_manage: boolean;
    org_map?: DisasterMapConfig | null;
};

export default function Assets({
    advisory,
    authority = null,
    medical,
    staging,
    critical,
    overdue_inspections = [],
    medical_store_url,
    staging_store_url,
    critical_store_url,
    condition_store_url,
    generator_statuses,
    resource_statuses,
    critical_site_types,
    condition_grades = [],
    can_manage,
    org_map = null,
}: Props) {
    const { t } = useTranslation();
    return (
        <AppLayout
            title={t('disaster.assets_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.assets_short') }]}
        >
            <PageHeader title={t('disaster.assets')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <DisasterMap config={org_map} className="mb-6" />

            <AssetSection title={t('disaster.overdue_inspections')} count={overdue_inspections.length} className="mb-6">
                {overdue_inspections.length === 0 ? (
                    <Empty>{t('disaster.empty.overdue_inspections')}</Empty>
                ) : (
                    <ul className="divide-y divide-border text-sm">
                        {overdue_inspections.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <span className="font-medium">{row.site?.name ?? `#${row.site_id}`}</span>
                                <span className="text-xs text-muted-foreground">
                                    {termLabel(t, disasterTermPrefix.conditionGrade, row.condition_grade)}
                                </span>
                                {row.next_inspection_due ? (
                                    <span dir="ltr" className="font-mono text-xs text-destructive">{row.next_inspection_due.slice(0, 10)}</span>
                                ) : null}
                                {row.inspector ? <span className="text-xs text-muted-foreground">{row.inspector}</span> : null}
                                {can_manage ? (
                                    <Button asChild variant="ghost" size="sm" className="ms-auto">
                                        <Link
                                            href={`/x/disaster/infrastructure-conditions/${row.id}`}
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
                {can_manage && condition_store_url ? (
                    <ConditionForm
                        store_url={condition_store_url}
                        critical={critical}
                        condition_grades={condition_grades}
                    />
                ) : null}
            </AssetSection>

            <AssetSection title={t('disaster.medical_facilities')} count={medical.length}>
                {medical.length === 0 ? (
                    <Empty>{t('disaster.empty.medical_facilities')}</Empty>
                ) : (
                    <ul className="divide-y divide-border text-sm">
                        {medical.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <span className="font-medium">{row.name}</span>
                                <span dir="ltr" className="font-mono text-xs text-muted-foreground">{row.facility_id}</span>
                                {row.latitude != null && row.longitude != null ? (
                                    <span className="text-xs text-muted-foreground">
                                        {row.latitude.toFixed(4)}, {row.longitude.toFixed(4)}
                                    </span>
                                ) : null}
                                {can_manage ? (
                                    <Button asChild variant="ghost" size="sm" className="ms-auto">
                                        <Link
                                            href={`/x/disaster/medical-facilities/${row.id}`}
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
                {can_manage ? (
                    <MedicalForm store_url={medical_store_url} generator_statuses={generator_statuses} resource_statuses={resource_statuses} />
                ) : null}
            </AssetSection>

            <AssetSection title={t('disaster.staging_areas')} count={staging.length} className="mt-6">
                {staging.length === 0 ? <Empty>{t('disaster.empty.staging_areas')}</Empty> : null}
                <ul className="divide-y divide-border text-sm">
                    {staging.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                            <span className="font-medium">{row.name}</span>
                            <span dir="ltr" className="font-mono text-xs text-muted-foreground">{row.staging_id}</span>
                            {can_manage ? (
                                <Button asChild variant="ghost" size="sm" className="ms-auto">
                                    <Link
                                        href={`/x/disaster/staging-areas/${row.id}`}
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
                {can_manage ? <StagingForm store_url={staging_store_url} resource_statuses={resource_statuses} /> : null}
            </AssetSection>

            <AssetSection title={t('disaster.critical_sites')} count={critical.length} className="mt-6">
                {critical.length === 0 ? <Empty>{t('disaster.empty.critical_sites')}</Empty> : null}
                <ul className="divide-y divide-border text-sm">
                    {critical.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                            <span className="font-medium">{row.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {termLabel(t, disasterTermPrefix.siteType, row.type)}
                            </span>
                            {row.downstream_zone_layer_id != null ? (
                                <span dir="ltr" className="font-mono text-xs text-muted-foreground">
                                    zone:{row.downstream_zone_layer_id}
                                </span>
                            ) : null}
                            {can_manage ? (
                                <Button asChild variant="ghost" size="sm" className="ms-auto">
                                    <Link
                                        href={`/x/disaster/critical-sites/${row.id}`}
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
                {can_manage ? (
                    <CriticalForm store_url={critical_store_url} critical_site_types={critical_site_types} />
                ) : null}
            </AssetSection>
        </AppLayout>
    );
}

function AssetSection({
    title,
    count,
    children,
    className = '',
}: {
    title: string;
    count: number;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Panel className={className}>
            <div className="border-b border-border px-3 py-2">
                <h2 className="text-sm font-semibold">
                    {title} <span className="font-normal text-muted-foreground">({count})</span>
                </h2>
            </div>
            {children}
        </Panel>
    );
}

function MedicalForm({
    store_url,
    generator_statuses,
    resource_statuses,
}: {
    store_url: string;
    generator_statuses: string[];
    resource_statuses: string[];
}) {
    const { t } = useTranslation();
    const form = useForm({
        facility_id: '',
        name: '',
        bed_capacity: 0,
        latitude: 32.88,
        longitude: 13.19,
        generator_status: generator_statuses[0] ?? 'ONLINE',
        resource_status: resource_statuses[0] ?? '01_Available',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, { preserveScroll: true, onSuccess: () => form.reset('facility_id', 'name') });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 border-t border-border p-3 sm:grid-cols-2">
            <Field label={t('disaster.facility_id')} error={form.errors.facility_id}>
                <TextInput value={form.data.facility_id} required onChange={(e) => form.setData('facility_id', e.target.value)} />
            </Field>
            <Field label={t('forms.name')} error={form.errors.name}>
                <TextInput value={form.data.name} required onChange={(e) => form.setData('name', e.target.value)} />
            </Field>
            <Field label={t('forms.latitude')} error={form.errors.latitude}>
                <TextInput type="number" step="0.0001" value={String(form.data.latitude)} onChange={(e) => form.setData('latitude', Number(e.target.value))} />
            </Field>
            <Field label={t('forms.longitude')} error={form.errors.longitude}>
                <TextInput type="number" step="0.0001" value={String(form.data.longitude)} onChange={(e) => form.setData('longitude', Number(e.target.value))} />
            </Field>
            <Field label={t('disaster.generator_status_label')} error={form.errors.generator_status}>
                <Select value={form.data.generator_status} onChange={(e) => form.setData('generator_status', e.target.value)}>
                    {generator_statuses.map((status) => (
                        <option key={status} value={status}>
                            {termLabel(t, disasterTermPrefix.generatorStatus, status)}
                        </option>
                    ))}
                </Select>
            </Field>
            <Field label={t('disaster.resource_status_label')} error={form.errors.resource_status}>
                <Select value={form.data.resource_status} onChange={(e) => form.setData('resource_status', e.target.value)}>
                    {resource_statuses.map((status) => (
                        <option key={status} value={status}>
                            {termLabel(t, disasterTermPrefix.resourceStatus, status)}
                        </option>
                    ))}
                </Select>
            </Field>
            <PrimaryButton type="submit" disabled={form.processing} className="sm:col-span-2">
                {t('disaster.add_medical')}
            </PrimaryButton>
        </form>
    );
}

function StagingForm({ store_url, resource_statuses }: { store_url: string; resource_statuses: string[] }) {
    const { t } = useTranslation();
    const form = useForm({
        staging_id: '',
        name: '',
        max_vehicle_cap: 10,
        latitude: 32.88,
        longitude: 13.19,
        resource_status: resource_statuses[0] ?? '01_Available',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, { preserveScroll: true, onSuccess: () => form.reset('staging_id', 'name') });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 border-t border-border p-3 sm:grid-cols-2">
            <Field label={t('disaster.staging_id')} error={form.errors.staging_id}>
                <TextInput value={form.data.staging_id} required onChange={(e) => form.setData('staging_id', e.target.value)} />
            </Field>
            <Field label={t('forms.name')} error={form.errors.name}>
                <TextInput value={form.data.name} required onChange={(e) => form.setData('name', e.target.value)} />
            </Field>
            <Field label={t('forms.latitude')} error={form.errors.latitude}>
                <TextInput type="number" step="0.0001" value={String(form.data.latitude)} onChange={(e) => form.setData('latitude', Number(e.target.value))} />
            </Field>
            <Field label={t('forms.longitude')} error={form.errors.longitude}>
                <TextInput type="number" step="0.0001" value={String(form.data.longitude)} onChange={(e) => form.setData('longitude', Number(e.target.value))} />
            </Field>
            <Field label={t('disaster.resource_status_label')} error={form.errors.resource_status}>
                <Select value={form.data.resource_status} onChange={(e) => form.setData('resource_status', e.target.value)}>
                    {resource_statuses.map((status) => (
                        <option key={status} value={status}>
                            {termLabel(t, disasterTermPrefix.resourceStatus, status)}
                        </option>
                    ))}
                </Select>
            </Field>
            <PrimaryButton type="submit" disabled={form.processing} className="sm:col-span-2">
                {t('disaster.add_staging')}
            </PrimaryButton>
        </form>
    );
}

function CriticalForm({ store_url, critical_site_types }: { store_url: string; critical_site_types: string[] }) {
    const { t } = useTranslation();
    const form = useForm({
        site_id: '',
        type: critical_site_types[0] ?? 'shelter',
        name: '',
        latitude: 32.88,
        longitude: 13.19,
        capacity_mcm: '' as string | number,
        height_m: '' as string | number,
        year_built: '' as string | number,
        spillway_capacity: '' as string | number,
        downstream_zone_layer_id: '' as string | number,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            capacity_mcm: data.capacity_mcm === '' ? null : Number(data.capacity_mcm),
            height_m: data.height_m === '' ? null : Number(data.height_m),
            year_built: data.year_built === '' ? null : Number(data.year_built),
            spillway_capacity: data.spillway_capacity === '' ? null : Number(data.spillway_capacity),
            downstream_zone_layer_id: data.downstream_zone_layer_id === '' ? null : Number(data.downstream_zone_layer_id),
        }));
        form.post(store_url, { preserveScroll: true, onSuccess: () => form.reset('site_id', 'name') });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 border-t border-border p-3 sm:grid-cols-2">
            <Field label={t('disaster.site_id')} error={form.errors.site_id}>
                <TextInput value={form.data.site_id} required onChange={(e) => form.setData('site_id', e.target.value)} />
            </Field>
            <Field label={t('forms.type')} error={form.errors.type}>
                <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                    {critical_site_types.map((siteType) => (
                        <option key={siteType} value={siteType}>
                            {termLabel(t, disasterTermPrefix.siteType, siteType)}
                        </option>
                    ))}
                </Select>
            </Field>
            <Field label={t('forms.name')} error={form.errors.name}>
                <TextInput value={form.data.name} required onChange={(e) => form.setData('name', e.target.value)} />
            </Field>
            <Field label={t('forms.latitude')} error={form.errors.latitude}>
                <TextInput type="number" step="0.0001" value={String(form.data.latitude)} onChange={(e) => form.setData('latitude', Number(e.target.value))} />
            </Field>
            <Field label={t('forms.longitude')} error={form.errors.longitude}>
                <TextInput type="number" step="0.0001" value={String(form.data.longitude)} onChange={(e) => form.setData('longitude', Number(e.target.value))} />
            </Field>
            <Field label={fieldLabel('capacity_mcm')} error={form.errors.capacity_mcm}>
                <TextInput type="number" step="0.001" value={String(form.data.capacity_mcm)} onChange={(e) => form.setData('capacity_mcm', e.target.value)} />
            </Field>
            <Field label={fieldLabel('height_m')} error={form.errors.height_m}>
                <TextInput type="number" step="0.01" value={String(form.data.height_m)} onChange={(e) => form.setData('height_m', e.target.value)} />
            </Field>
            <Field label={fieldLabel('year_built')} error={form.errors.year_built}>
                <TextInput type="number" value={String(form.data.year_built)} onChange={(e) => form.setData('year_built', e.target.value)} />
            </Field>
            <Field label={fieldLabel('spillway_capacity')} error={form.errors.spillway_capacity}>
                <TextInput type="number" step="0.001" value={String(form.data.spillway_capacity)} onChange={(e) => form.setData('spillway_capacity', e.target.value)} />
            </Field>
            <Field label={fieldLabel('downstream_zone_layer_id')} error={form.errors.downstream_zone_layer_id}>
                <TextInput type="number" value={String(form.data.downstream_zone_layer_id)} onChange={(e) => form.setData('downstream_zone_layer_id', e.target.value)} />
            </Field>
            <PrimaryButton type="submit" disabled={form.processing} className="sm:col-span-2">
                {t('disaster.add_critical')}
            </PrimaryButton>
        </form>
    );
}

function ConditionForm({
    store_url,
    critical,
    condition_grades,
}: {
    store_url: string;
    critical: CriticalRow[];
    condition_grades: string[];
}) {
    const { t } = useTranslation();
    const form = useForm({
        site_id: critical[0]?.id ?? 0,
        condition_grade: condition_grades[0] ?? 'good',
        last_inspection_at: new Date().toISOString().slice(0, 10),
        next_inspection_due: '',
        maintenance_status: '',
        inspector: '',
        notes: '',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            site_id: Number(data.site_id),
            next_inspection_due: data.next_inspection_due || null,
            maintenance_status: data.maintenance_status || null,
            inspector: data.inspector || null,
            notes: data.notes || null,
        }));
        form.post(store_url, {
            preserveScroll: true,
            onSuccess: () => form.reset('notes', 'inspector', 'maintenance_status'),
        });
    }

    if (critical.length === 0) {
        return null;
    }

    return (
        <form onSubmit={submit} className="grid gap-3 border-t border-border p-3 sm:grid-cols-2">
            <Field label={t('disaster.critical_sites')} error={form.errors.site_id}>
                <Select value={String(form.data.site_id)} onChange={(e) => form.setData('site_id', Number(e.target.value))}>
                    {critical.map((site) => (
                        <option key={site.id} value={site.id}>
                            {site.name} ({site.site_id})
                        </option>
                    ))}
                </Select>
            </Field>
            <Field label={t('disaster.condition_grade_label')} error={form.errors.condition_grade}>
                <Select value={form.data.condition_grade} onChange={(e) => form.setData('condition_grade', e.target.value)}>
                    {condition_grades.map((grade) => (
                        <option key={grade} value={grade}>
                            {termLabel(t, disasterTermPrefix.conditionGrade, grade)}
                        </option>
                    ))}
                </Select>
            </Field>
            <Field label={t('disaster.last_inspection_at')} error={form.errors.last_inspection_at}>
                <TextInput type="date" value={form.data.last_inspection_at} onChange={(e) => form.setData('last_inspection_at', e.target.value)} />
            </Field>
            <Field label={t('disaster.next_inspection_due')} error={form.errors.next_inspection_due}>
                <TextInput type="date" value={form.data.next_inspection_due} onChange={(e) => form.setData('next_inspection_due', e.target.value)} />
            </Field>
            <Field label={t('disaster.inspector')} error={form.errors.inspector}>
                <TextInput value={form.data.inspector} onChange={(e) => form.setData('inspector', e.target.value)} />
            </Field>
            <Field label={t('disaster.maintenance_status')} error={form.errors.maintenance_status}>
                <TextInput value={form.data.maintenance_status} onChange={(e) => form.setData('maintenance_status', e.target.value)} />
            </Field>
            <PrimaryButton type="submit" disabled={form.processing} className="sm:col-span-2">
                {t('disaster.add_condition')}
            </PrimaryButton>
        </form>
    );
}
