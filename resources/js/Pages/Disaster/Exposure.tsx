import { useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { when } from '@/lib/format';
import AdvisoryBanner from './AdvisoryBanner';
import { disasterTermPrefix, termLabel } from '@/lib/disaster-terms';

const HAZARD_KEYS = ['floodplain', 'fault', 'wildfire', 'demographic', 'ew_risk'] as const;

type Summary = {
    id: number;
    hazard_key: string;
    asset_type: string;
    asset_id: number;
    distance_m: number;
    in_hazard: boolean;
    computed_at?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    summaries: Summary[];
    compute_url: string;
    can_manage: boolean;
};

export default function Exposure({ advisory, authority = null, summaries, compute_url, can_manage }: Props) {
    const { t } = useTranslation();
    const form = useForm({
        hazard_key: 'floodplain',
        latitude: 32.88,
        longitude: 13.19,
        radius_m: 5000,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(compute_url, { preserveScroll: true });
    }

    return (
        <AppLayout
            title={t('disaster.exposure_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.exposure_short') }]}
        >
            <PageHeader title={t('disaster.exposure_page')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            {can_manage ? (
                <Panel className="mb-6 max-w-xl">
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('early_warning.compute_proximity')}</h2>
                    </div>
                    <form onSubmit={submit} className="space-y-3 p-3">
                        <Field label={t('disaster.hazard_key')} error={form.errors.hazard_key}>
                            <Select value={form.data.hazard_key} onChange={(e) => form.setData('hazard_key', e.target.value)}>
                                {HAZARD_KEYS.map((key) => (
                                    <option key={key} value={key}>
                                        {termLabel(t, disasterTermPrefix.hazardKey, key)}
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
                        <Field label={t('disaster.radius_m')} error={form.errors.radius_m}>
                            <TextInput type="number" min={1} value={String(form.data.radius_m)} onChange={(e) => form.setData('radius_m', Number(e.target.value))} />
                        </Field>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {t('disaster.run_compute')}
                        </PrimaryButton>
                    </form>
                </Panel>
            ) : null}

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.summaries')}</h2>
                </div>
                {summaries.length === 0 ? (
                    <Empty>{t('disaster.empty.exposure_summaries')}</Empty>
                ) : (
                    <ul className="divide-y divide-border text-sm">
                        {summaries.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <span className="font-medium">
                                    {termLabel(t, disasterTermPrefix.hazardKey, s.hazard_key)}
                                </span>
                                <span className="text-muted-foreground">
                                    {termLabel(t, disasterTermPrefix.assetType, s.asset_type)} #{s.asset_id}
                                </span>
                                <Badge variant={s.in_hazard ? 'destructive' : 'secondary'}>
                                    {s.in_hazard ? t('disaster.in_hazard') : t('disaster.outside')}
                                </Badge>
                                <span dir="ltr" className="font-mono text-xs text-muted-foreground">{s.distance_m} m</span>
                                <span className="ms-auto text-xs text-muted-foreground">
                                    {s.computed_at ? when(s.computed_at) : t('common.em_dash')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}
