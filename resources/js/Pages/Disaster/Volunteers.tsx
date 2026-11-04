import { Link, useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';

type VolunteerRow = {
    id: number;
    name: string;
    skills: string[];
    availability: string;
    organization_name?: string | null;
    status: string;
    destroy_url: string;
};

type Option = { value: string; label: string };

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    volunteers: VolunteerRow[];
    status_options: Option[];
    store_url: string;
    can_manage: boolean;
};

export default function Volunteers({
    advisory,
    authority = null,
    volunteers,
    status_options,
    store_url,
    can_manage,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        name: '',
        skills: '',
        availability: 'available',
        organization_name: '',
        status: 'active',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'skills', 'organization_name'),
        });
    }

    return (
        <AppLayout
            title={t('disaster.volunteers')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.volunteers_short') },
            ]}
        >
            <PageHeader title={t('disaster.volunteers')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.volunteers_list')}</h2>
                    </div>
                    {volunteers.length === 0 ? (
                        <Empty>{t('disaster.empty.volunteers')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {volunteers.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                    <div>
                                        <div className="font-medium">{row.name}</div>
                                        <div className="text-muted-foreground">
                                            {enumLabel(row.status)} · {enumLabel(row.availability)}
                                            {row.organization_name ? ` · ${row.organization_name}` : ''}
                                            {row.skills.length > 0 ? ` · ${row.skills.join(', ')}` : ''}
                                        </div>
                                    </div>
                                    {can_manage ? (
                                        <Button asChild variant="ghost" size="sm" className="ms-auto">
                                            <Link href={row.destroy_url} method="delete" as="button" preserveScroll>
                                                {t('common.delete')}
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
                            <h2 className="text-sm font-semibold">{t('disaster.add_volunteer')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('forms.name')} error={form.errors.name}>
                                <TextInput
                                    value={form.data.name}
                                    required
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.skills')} error={form.errors.skills}>
                                <TextInput
                                    value={form.data.skills}
                                    placeholder={t('disaster.skills_placeholder')}
                                    onChange={(e) => form.setData('skills', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.availability')} error={form.errors.availability}>
                                <TextInput
                                    value={form.data.availability}
                                    onChange={(e) => form.setData('availability', e.target.value)}
                                />
                            </Field>
                            <Field label={t('disaster.organization_name')} error={form.errors.organization_name}>
                                <TextInput
                                    value={form.data.organization_name}
                                    onChange={(e) => form.setData('organization_name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('forms.status')}>
                                <Select
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                >
                                    {status_options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <PrimaryButton type="submit" disabled={form.processing}>
                                {t('disaster.add_volunteer')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : (
                    <Panel>
                        <p className="p-3 text-sm text-muted-foreground">{t('disaster.manage_required_volunteers')}</p>
                    </Panel>
                )}
            </div>
        </AppLayout>
    );
}
