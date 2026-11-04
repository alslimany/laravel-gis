import { Link, useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';
import { disasterTermPrefix, termLabel } from '@/lib/disaster-terms';

type Incident = {
    id: number;
    external_id: string;
    title: string;
    status: string;
    priority: number;
    latitude?: number | null;
    longitude?: number | null;
    shared?: boolean;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    incidents: Incident[];
    shared_incidents?: Incident[];
    store_url: string;
    status_options: string[];
    can_dispatch: boolean;
};

export default function Incidents({
    advisory,
    authority = null,
    incidents,
    shared_incidents = [],
    store_url,
    status_options,
    can_dispatch,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        external_id: '',
        title: '',
        status: 'open',
        priority: 3,
        latitude: 32.88,
        longitude: 13.19,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, { preserveScroll: true, onSuccess: () => form.reset('external_id', 'title') });
    }

    return (
        <AppLayout
            title={t('disaster.incidents_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.incidents_short') }]}
        >
            <PageHeader title={t('disaster.incidents')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="grid gap-6 lg:grid-cols-2">
                <div className="space-y-6">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.active_list')}</h2>
                        </div>
                        {incidents.length === 0 ? (
                            <Empty>{t('disaster.empty.incidents')}</Empty>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {incidents.map((i) => (
                                    <li key={i.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                        <Link
                                            href={`/x/disaster/incidents/${i.id}`}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {i.title}
                                        </Link>
                                        <Badge variant="secondary">
                                            {termLabel(t, disasterTermPrefix.incidentStatus, i.status)}
                                        </Badge>
                                        {can_dispatch ? (
                                            <Button asChild variant="ghost" size="sm" className="ms-auto">
                                                <Link
                                                    href={`/x/disaster/incidents/${i.id}`}
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

                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.shared_incidents')}</h2>
                        </div>
                        {shared_incidents.length === 0 ? (
                            <Empty>{t('disaster.empty.shared_incidents')}</Empty>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {shared_incidents.map((i) => (
                                    <li key={`shared-${i.id}`} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                        <Link
                                            href={`/x/disaster/incidents/${i.id}`}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {i.title}
                                        </Link>
                                        <Badge variant="outline">{t('disaster.shared_badge')}</Badge>
                                        <Badge variant="secondary">
                                            {termLabel(t, disasterTermPrefix.incidentStatus, i.status)}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                {can_dispatch ? (
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.create_incident')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('forms.external_id')} error={form.errors.external_id}>
                                <TextInput value={form.data.external_id} required onChange={(e) => form.setData('external_id', e.target.value)} />
                            </Field>
                            <Field label={t('forms.title')} error={form.errors.title}>
                                <TextInput value={form.data.title} required onChange={(e) => form.setData('title', e.target.value)} />
                            </Field>
                            <Field label={t('forms.status')}>
                                <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                    {status_options.map((s) => (
                                        <option key={s} value={s}>
                                            {termLabel(t, disasterTermPrefix.incidentStatus, s)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label={t('forms.latitude')} error={form.errors.latitude}>
                                    <TextInput type="number" step="0.0001" value={String(form.data.latitude)} onChange={(e) => form.setData('latitude', Number(e.target.value))} />
                                </Field>
                                <Field label={t('forms.longitude')} error={form.errors.longitude}>
                                    <TextInput type="number" step="0.0001" value={String(form.data.longitude)} onChange={(e) => form.setData('longitude', Number(e.target.value))} />
                                </Field>
                            </div>
                            <PrimaryButton type="submit" disabled={form.processing}>
                                {t('disaster.create_incident')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : null}
            </div>
        </AppLayout>
    );
}
