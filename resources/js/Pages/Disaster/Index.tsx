import { Link, useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { PageHeader, Panel, PrimaryLink, PrimaryButton, Field, TextInput } from '@/components/gis';
import AdvisoryBanner from './AdvisoryBanner';

type CapLog = {
    id: number;
    channel: string;
    created_at?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    counts: Record<string, number>;
    hazardsUrl: string;
    assetsUrl: string;
    planningUrl: string;
    incidentsUrl: string;
    activationsUrl: string;
    damageUrl: string;
    exposureUrl: string;
    avlUrl: string;
    publicTokensUrl: string;
    closuresUrl: string;
    cap_draft_url: string;
    can_public_publish: boolean;
    recent_cap_logs: CapLog[];
};

export default function Index({
    advisory,
    authority = null,
    counts,
    hazardsUrl,
    assetsUrl,
    planningUrl,
    incidentsUrl,
    activationsUrl,
    damageUrl,
    exposureUrl,
    avlUrl,
    publicTokensUrl,
    closuresUrl,
    cap_draft_url,
    can_public_publish,
    recent_cap_logs,
}: Props) {
    const { t } = useTranslation();
    const capForm = useForm({
        headline: t('disaster.draft_advisory'),
        description: t('disaster.cap_stub_description'),
        event: 'Disaster',
    });

    function issueCapStub(event: React.FormEvent) {
        event.preventDefault();
        capForm.post(cap_draft_url, { preserveScroll: true });
    }

    return (
        <AppLayout title={t('disaster.title')} breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }]}>
            <PageHeader
                title={t('disaster.title_long')}
                action={
                    <div className="flex flex-wrap gap-2">
                        <PrimaryLink href="/x/disaster/cop">{t('disaster.cop_short')}</PrimaryLink>
                        <PrimaryLink href={hazardsUrl}>{t('disaster.hazards_short')}</PrimaryLink>
                        <PrimaryLink href={activationsUrl}>{t('disaster.activations_short')}</PrimaryLink>
                        <PrimaryLink href={incidentsUrl}>{t('disaster.incidents_short')}</PrimaryLink>
                    </div>
                }
            />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label={t('disaster.medical_facilities')} value={String(counts.medical_facilities ?? 0)} />
                <Stat label={t('disaster.staging_areas')} value={String(counts.staging_areas ?? 0)} />
                <Stat label={t('disaster.critical_sites')} value={String(counts.critical_sites ?? 0)} />
                <Stat label={t('disaster.open_incidents')} value={String(counts.open_incidents ?? 0)} />
                <Stat label={t('disaster.activations_short')} value={String(counts.activations ?? 0)} />
                <Stat label={t('disaster.avl_page')} value={String(counts.avl_units ?? 0)} />
                <Stat label={t('disaster.damage_points')} value={String(counts.damage_points ?? 0)} />
                <Stat label={t('disaster.closures')} value={String(counts.closures ?? 0)} />
                <Stat label={t('disaster.exposure_rows')} value={String(counts.exposure_summaries ?? 0)} />
            </div>

            <Panel className="mb-6">
                <div className="flex flex-wrap gap-4 px-3 py-3 text-sm">
                    <Link href={assetsUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.nav.assets_registry')}
                    </Link>
                    <Link href={planningUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.nav.evacuation_planning')}
                    </Link>
                    <Link href={damageUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.damage_reports')}
                    </Link>
                    <Link href={exposureUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.exposure_short')}
                    </Link>
                    <Link href={avlUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.avl_short')}
                    </Link>
                    <Link href={publicTokensUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.public_tokens_short')}
                    </Link>
                    <Link href={closuresUrl} className="font-medium text-primary hover:underline">
                        {t('disaster.closures')}
                    </Link>
                </div>
            </Panel>

            {can_public_publish ? (
                <Panel className="max-w-xl">
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">{t('disaster.issue_cap_stub')}</h2>
                    </div>
                    <form onSubmit={issueCapStub} className="space-y-3 p-3">
                        <Field label={t('forms.headline')}>
                            <TextInput value={capForm.data.headline} onChange={(e) => capForm.setData('headline', e.target.value)} />
                        </Field>
                        <Field label={t('forms.description')}>
                            <TextInput value={capForm.data.description} onChange={(e) => capForm.setData('description', e.target.value)} />
                        </Field>
                        <PrimaryButton type="submit" disabled={capForm.processing}>
                            {t('disaster.log_cap_draft')}
                        </PrimaryButton>
                    </form>
                    {recent_cap_logs.length > 0 ? (
                        <ul className="border-t border-border px-3 py-2 text-xs text-muted-foreground">
                            {recent_cap_logs.map((log) => (
                                <li key={log.id}>
                                    {t('disaster.cap_log', {
                                        id: log.id,
                                        date: log.created_at ?? t('common.em_dash'),
                                    })}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </Panel>
            ) : null}
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-border bg-card px-3 py-2">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div dir="ltr" className="text-lg font-semibold tabular-nums">{value}</div>
        </div>
    );
}
