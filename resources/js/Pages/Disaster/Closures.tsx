import { Link, useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';
import { disasterTermPrefix, termLabel } from '@/lib/disaster-terms';

const CLOSURE_TYPES = ['road', 'bridge', 'route'] as const;
const SEVERITIES = ['minor', 'moderate', 'major', 'critical'] as const;

type ClosureRow = {
    id: number;
    external_id: string;
    closure_type?: string | null;
    severity?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    closures: ClosureRow[];
    store_url: string;
    can_manage: boolean;
};

export default function Closures({ advisory, authority = null, closures, store_url, can_manage }: Props) {
    const { t } = useTranslation();
    const form = useForm({
        external_id: '',
        closure_type: 'road',
        severity: 'major',
        geojson_text: '{"type":"LineString","coordinates":[[13.19,32.88],[13.21,32.87]]}',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, { preserveScroll: true, onSuccess: () => form.reset('external_id') });
    }

    return (
        <AppLayout
            title={t('disaster.closures')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.closures') }]}
        >
            <PageHeader title={t('disaster.closures_page')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.closures')}</h2>
                    </div>
                    {closures.length === 0 ? (
                        <Empty>{t('disaster.empty.closures')}</Empty>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {closures.map((row) => (
                                <li key={row.id} className="flex items-center gap-2 px-3 py-2">
                                    <span className="font-medium">{row.external_id}</span>
                                    <span className="text-muted-foreground">
                                        {row.closure_type
                                            ? termLabel(t, disasterTermPrefix.closureType, row.closure_type)
                                            : t('common.em_dash')}
                                        {row.severity
                                            ? ` · ${termLabel(t, disasterTermPrefix.severity, row.severity)}`
                                            : ''}
                                    </span>
                                    {can_manage ? (
                                        <Button asChild variant="ghost" size="sm" className="ms-auto">
                                            <Link
                                                href={`/x/disaster/closures/${row.id}`}
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
                            <h2 className="text-sm font-semibold">{t('disaster.add_closure')}</h2>
                        </div>
                        <form onSubmit={submit} className="space-y-3 p-3">
                            <Field label={t('forms.external_id')} error={form.errors.external_id}>
                                <TextInput value={form.data.external_id} required onChange={(e) => form.setData('external_id', e.target.value)} />
                            </Field>
                            <Field label={t('forms.type')}>
                                <Select value={form.data.closure_type} onChange={(e) => form.setData('closure_type', e.target.value)}>
                                    {CLOSURE_TYPES.map((type) => (
                                        <option key={type} value={type}>
                                            {termLabel(t, disasterTermPrefix.closureType, type)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.severity_label')}>
                                <Select value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)}>
                                    {SEVERITIES.map((severity) => (
                                        <option key={severity} value={severity}>
                                            {termLabel(t, disasterTermPrefix.severity, severity)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('disaster.geojson_linestring')}>
                                <textarea
                                    className="min-h-[8rem] w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                                    value={form.data.geojson_text}
                                    onChange={(e) => form.setData('geojson_text', e.target.value)}
                                />
                            </Field>
                            <PrimaryButton type="submit" disabled={form.processing}>
                                {t('disaster.create_closure')}
                            </PrimaryButton>
                        </form>
                    </Panel>
                ) : null}
            </div>
        </AppLayout>
    );
}
