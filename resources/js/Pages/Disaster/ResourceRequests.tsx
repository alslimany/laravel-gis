import { Link, router, useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';

type AssignmentRow = {
    id: number;
    warehouse_id?: number | null;
    warehouse_name?: string | null;
    quantity: number;
};

type RequestRow = {
    id: number;
    incident_id: number;
    incident_title?: string | null;
    incident_external_id?: string | null;
    incident_url?: string | null;
    resource_type_name?: string | null;
    resource_type_unit?: string | null;
    quantity: number;
    priority: string;
    status: string;
    requester_name?: string | null;
    assignments: AssignmentRow[];
    allowed_transitions: string[];
    transition_url: string;
    match_url: string;
};

type Option = { value: string; label: string };

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    requests: RequestRow[];
    incidents: { id: number; external_id: string; title: string }[];
    resource_types: { id: number; name: string; unit?: string | null }[];
    status_options: Option[];
    priority_options: Option[];
    store_url: string;
    can_dispatch: boolean;
};

export default function ResourceRequests({
    advisory,
    authority = null,
    requests,
    incidents,
    resource_types,
    priority_options,
    store_url,
    can_dispatch,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        incident_id: incidents[0]?.id ?? '',
        resource_type_id: resource_types[0]?.id ?? '',
        quantity: 1,
        priority: 'normal',
        needed_by: '',
        notes: '',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, {
            preserveScroll: true,
            onSuccess: () => form.reset('quantity', 'notes', 'needed_by'),
        });
    }

    function transition(row: RequestRow, status: string) {
        router.post(row.transition_url, { status }, { preserveScroll: true });
    }

    function match(row: RequestRow) {
        router.post(row.match_url, {}, { preserveScroll: true });
    }

    return (
        <AppLayout
            title={t('disaster.resource_requests')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.resource_requests_short') },
            ]}
        >
            <PageHeader title={t('disaster.resource_requests')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.resource_requests_list')}</h2>
                    </div>
                    {requests.length === 0 ? (
                        <Empty>{t('disaster.empty.resource_requests')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {requests.map((row) => (
                                <li key={row.id} className="space-y-2 px-3 py-3">
                                    <div className="flex flex-wrap items-baseline gap-2">
                                        <span className="font-medium">
                                            #{row.id} · {row.quantity} {row.resource_type_unit ?? ''} {row.resource_type_name}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {enumLabel(row.status)} · {enumLabel(row.priority)}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground">
                                        {row.incident_url ? (
                                            <Link href={row.incident_url} className="underline-offset-2 hover:underline">
                                                {row.incident_external_id ?? row.incident_title}
                                            </Link>
                                        ) : (
                                            row.incident_title
                                        )}
                                        {row.requester_name ? ` · ${row.requester_name}` : ''}
                                    </div>
                                    {row.assignments.length > 0 ? (
                                        <div className="text-xs text-muted-foreground">
                                            {row.assignments
                                                .map((a) => `${a.warehouse_name ?? 'unit'} × ${a.quantity}`)
                                                .join(', ')}
                                        </div>
                                    ) : null}
                                    {can_dispatch ? (
                                        <div className="flex flex-wrap gap-2">
                                            {row.status === 'approved' ? (
                                                <Button type="button" size="sm" variant="secondary" onClick={() => match(row)}>
                                                    {t('disaster.match_request')}
                                                </Button>
                                            ) : null}
                                            {row.allowed_transitions
                                                .filter((status) => status !== 'matched')
                                                .map((status) => (
                                                    <Button
                                                        key={status}
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => transition(row, status)}
                                                    >
                                                        {status}
                                                    </Button>
                                                ))}
                                        </div>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                {can_dispatch ? (
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.create_resource_request')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('disaster.incident')} error={form.errors.incident_id}>
                                <Select
                                    value={String(form.data.incident_id)}
                                    onChange={(e) => form.setData('incident_id', Number(e.target.value))}
                                    required
                                >
                                    {incidents.map((incident) => (
                                        <option key={incident.id} value={incident.id}>
                                            {incident.external_id} — {incident.title}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.resource_type')} error={form.errors.resource_type_id}>
                                <Select
                                    value={String(form.data.resource_type_id)}
                                    onChange={(e) => form.setData('resource_type_id', Number(e.target.value))}
                                    required
                                >
                                    {resource_types.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.quantity')} error={form.errors.quantity}>
                                <TextInput
                                    type="number"
                                    min={1}
                                    value={form.data.quantity}
                                    onChange={(e) => form.setData('quantity', Number(e.target.value))}
                                    required
                                />
                            </Field>
                            <Field label={t('disaster.task_priority')} error={form.errors.priority}>
                                <Select
                                    value={form.data.priority}
                                    onChange={(e) => form.setData('priority', e.target.value)}
                                >
                                    {priority_options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.needed_by')} error={form.errors.needed_by}>
                                <TextInput
                                    type="datetime-local"
                                    value={form.data.needed_by}
                                    onChange={(e) => form.setData('needed_by', e.target.value)}
                                />
                            </Field>
                            <Field label={t('forms.notes')} error={form.errors.notes}>
                                <TextInput
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={form.processing || incidents.length === 0 || resource_types.length === 0}>
                                {t('disaster.create_resource_request')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : (
                    <Panel>
                        <p className="p-3 text-sm text-muted-foreground">{t('disaster.dispatch_required_requests')}</p>
                    </Panel>
                )}
            </div>
        </AppLayout>
    );
}
