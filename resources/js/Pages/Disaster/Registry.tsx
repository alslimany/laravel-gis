import { useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import AdvisoryBanner from './AdvisoryBanner';

type ShelterRow = {
    id: number;
    site_id: string;
    name: string;
    capacity: number | null;
    current_occupancy: number;
    capacity_warning: string | null;
};

type BeneficiaryRow = {
    id: number;
    household_code: string;
    household_size: number;
    shelter_site_id: number | null;
    has_contact_ref: boolean;
};

type MissingRow = {
    id: number;
    case_code: string;
    status: string;
    display_name_redacted: string | null;
    given_name?: string | null;
    family_name?: string | null;
    age_years: number | null;
};

type Option = { value: string; label: string };

type Occupancy = {
    shelter_count: number;
    total_capacity: number | null;
    total_occupancy: number;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    occupancy: Occupancy;
    shelters: ShelterRow[];
    beneficiaries: BeneficiaryRow[];
    missing_persons: MissingRow[];
    direction_options: Option[];
    status_options: Option[];
    checkin_url: string;
    beneficiaries_store_url: string;
    missing_store_url: string;
    can_registry: boolean;
};

export default function Registry({
    advisory,
    authority = null,
    occupancy,
    shelters,
    beneficiaries,
    missing_persons,
    direction_options,
    status_options,
    checkin_url,
    beneficiaries_store_url,
    missing_store_url,
    can_registry,
}: Props) {
    const { t } = useTranslation();

    const checkinForm = useForm({
        shelter_site_id: shelters[0]?.id?.toString() ?? '',
        direction: 'check_in',
        headcount: '1',
        notes: '',
    });

    const beneficiaryForm = useForm({
        household_code: '',
        household_size: '1',
        contact_ref: '',
        retained_until: '',
    });

    const missingForm = useForm({
        case_code: '',
        given_name: '',
        family_name: '',
        age_years: '',
        status: 'missing',
        retained_until: '',
    });

    return (
        <AppLayout
            title={t('disaster.registry')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.registry_short') },
            ]}
        >
            <PageHeader title={t('disaster.registry')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <p className="mb-6 max-w-2xl text-sm text-muted-foreground">{t('disaster.registry_privacy_note')}</p>

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <Panel>
                    <div className="p-3 text-sm">
                        <div className="text-muted-foreground">{t('disaster.registry_shelters')}</div>
                        <div className="text-2xl font-semibold">{occupancy.shelter_count}</div>
                    </div>
                </Panel>
                <Panel>
                    <div className="p-3 text-sm">
                        <div className="text-muted-foreground">{t('disaster.registry_occupancy')}</div>
                        <div className="text-2xl font-semibold">{occupancy.total_occupancy}</div>
                    </div>
                </Panel>
                <Panel>
                    <div className="p-3 text-sm">
                        <div className="text-muted-foreground">{t('disaster.registry_capacity')}</div>
                        <div className="text-2xl font-semibold">{occupancy.total_capacity ?? '—'}</div>
                    </div>
                </Panel>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.registry_shelter_list')}</h2>
                    </div>
                    {shelters.length === 0 ? (
                        <Empty>{t('disaster.empty.shelters')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {shelters.map((row) => (
                                <li key={row.id} className="px-3 py-2">
                                    <div className="font-medium">{row.name}</div>
                                    <div className="text-muted-foreground">
                                        {row.current_occupancy}
                                        {row.capacity != null ? ` / ${row.capacity}` : ''}
                                        {row.capacity_warning ? ` · ${row.capacity_warning}` : ''}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                {can_registry ? (
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.registry_checkin')}</h2>
                        </div>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                checkinForm.post(checkin_url, { preserveScroll: true });
                            }}
                            className="space-y-3 p-3"
                        >
                            <Field label={t('disaster.registry_shelter')} error={checkinForm.errors.shelter_site_id}>
                                <Select
                                    value={checkinForm.data.shelter_site_id}
                                    onChange={(e) => checkinForm.setData('shelter_site_id', e.target.value)}
                                    required
                                >
                                    <option value="">{t('common.select')}</option>
                                    {shelters.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.registry_direction')} error={checkinForm.errors.direction}>
                                <Select
                                    value={checkinForm.data.direction}
                                    onChange={(e) => checkinForm.setData('direction', e.target.value)}
                                >
                                    {direction_options.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.registry_headcount')} error={checkinForm.errors.headcount}>
                                <TextInput
                                    type="number"
                                    min={1}
                                    value={checkinForm.data.headcount}
                                    onChange={(e) => checkinForm.setData('headcount', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={checkinForm.processing}>
                                {t('disaster.registry_record_checkin')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : null}

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.registry_beneficiaries')}</h2>
                    </div>
                    {beneficiaries.length === 0 ? (
                        <Empty>{t('disaster.empty.beneficiaries')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {beneficiaries.map((row) => (
                                <li key={row.id} className="px-3 py-2">
                                    <div className="font-medium">{row.household_code}</div>
                                    <div className="text-muted-foreground">
                                        {t('disaster.registry_household_size')}: {row.household_size}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {can_registry ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                beneficiaryForm.post(beneficiaries_store_url, {
                                    preserveScroll: true,
                                    onSuccess: () => beneficiaryForm.reset('household_code', 'contact_ref'),
                                });
                            }}
                            className="space-y-3 border-t border-border p-3"
                        >
                            <Field label={t('disaster.registry_household_code')} error={beneficiaryForm.errors.household_code}>
                                <TextInput
                                    value={beneficiaryForm.data.household_code}
                                    required
                                    onChange={(e) => beneficiaryForm.setData('household_code', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.registry_household_size')} error={beneficiaryForm.errors.household_size}>
                                <TextInput
                                    type="number"
                                    min={1}
                                    value={beneficiaryForm.data.household_size}
                                    onChange={(e) => beneficiaryForm.setData('household_size', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.registry_contact_ref')} error={beneficiaryForm.errors.contact_ref}>
                                <TextInput
                                    value={beneficiaryForm.data.contact_ref}
                                    onChange={(e) => beneficiaryForm.setData('contact_ref', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={beneficiaryForm.processing}>
                                {t('disaster.registry_add_beneficiary')}
                            </PrimaryButton>
                        </form>
                    ) : null}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.registry_missing')}</h2>
                    </div>
                    {missing_persons.length === 0 ? (
                        <Empty>{t('disaster.empty.missing_persons')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {missing_persons.map((row) => (
                                <li key={row.id} className="px-3 py-2">
                                    <div className="font-medium">{row.case_code}</div>
                                    <div className="text-muted-foreground">
                                        {row.display_name_redacted ?? '—'} · {enumLabel(row.status)}
                                        {row.age_years != null ? ` · ${row.age_years}` : ''}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {can_registry ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                missingForm.post(missing_store_url, {
                                    preserveScroll: true,
                                    onSuccess: () => missingForm.reset('case_code', 'given_name', 'family_name', 'age_years'),
                                });
                            }}
                            className="space-y-3 border-t border-border p-3"
                        >
                            <Field label={t('disaster.registry_case_code')} error={missingForm.errors.case_code}>
                                <TextInput
                                    value={missingForm.data.case_code}
                                    required
                                    onChange={(e) => missingForm.setData('case_code', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.registry_given_name')} error={missingForm.errors.given_name}>
                                <TextInput
                                    value={missingForm.data.given_name}
                                    onChange={(e) => missingForm.setData('given_name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.registry_family_name')} error={missingForm.errors.family_name}>
                                <TextInput
                                    value={missingForm.data.family_name}
                                    onChange={(e) => missingForm.setData('family_name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.registry_age')} error={missingForm.errors.age_years}>
                                <TextInput
                                    type="number"
                                    min={0}
                                    value={missingForm.data.age_years}
                                    onChange={(e) => missingForm.setData('age_years', e.target.value)}
                                />
                            </Field>
                            <Field label={t('forms.status')} error={missingForm.errors.status}>
                                <Select
                                    value={missingForm.data.status}
                                    onChange={(e) => missingForm.setData('status', e.target.value)}
                                >
                                    {status_options.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <PrimaryButton type="submit" disabled={missingForm.processing}>
                                {t('disaster.registry_add_missing')}
                            </PrimaryButton>
                        </form>
                    ) : null}
                </Panel>
            </div>
        </AppLayout>
    );
}
