import { useEffect } from 'react';
import { useTranslation, __ } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { bytes, count, when } from '@/lib/format';
import { GhostLink, PageHeader, Panel, Pill, PrimaryLink } from '@/components/gis';

const TONES = { completed: 'live', processing: 'run', failed: 'fail', pending: 'idle' } as const;

const STATUS = {
    completed: 'Completed',
    processing: 'Processing',
    failed: 'Failed',
    pending: 'Pending',
} as const;

type ImportRecord = {
    id: number;
    file_name: string;
    file_type?: string | null;
    file_size?: number | null;
    status: keyof typeof STATUS | string;
    progress?: number | null;
    error_message?: string | null;
    feature_count?: number | null;
    geometry_type?: string | null;
    created_at?: string | null;
    completed_at?: string | null;
    metadata?: { layer_id?: number | null; name?: string | null } | null;
};

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-0.5 border-t py-3 first:border-t-0 first:pt-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd dir="ltr" className="text-sm tabular-nums">{value}</dd>
        </div>
    );
}

export default function Show({ import: record }: { import: ImportRecord }) {
    const { t } = useTranslation();
    const active = record.status === 'processing' || record.status === 'pending';
    const progress = Math.min(100, Math.max(0, Number(record.progress) || 0));
    const status = record.status in STATUS ? STATUS[record.status as keyof typeof STATUS] : record.status;
    const layerId = record.metadata?.layer_id;

    useEffect(() => {
        if (!active) {
            return undefined;
        }
        const timer = window.setInterval(() => {
            router.reload({ only: ['import'] });
        }, 3000);
        return () => window.clearInterval(timer);
    }, [active]);

    return (
        <AppLayout title={record.file_name}>
            <PageHeader title={record.file_name} action={<GhostLink href="/imports">{t('imports.back')}</GhostLink>} />
            <Panel className="max-w-xl space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Pill tone={TONES[record.status as keyof typeof TONES] || 'idle'}>{status}</Pill>
                    <span dir="ltr" className="text-sm tabular-nums text-muted-foreground">{count(progress)}%</span>
                </div>
                <div
                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-valuenow={progress}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label={t('imports.import_progress')}
                >
                    <div
                        className="h-full bg-primary transition-[width] duration-500 ease-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                {active ? (
                    <p className="text-sm text-muted-foreground">
                        {__('imports.refresh_note')}
                    </p>
                ) : null}
                {record.status === 'failed' ? (
                    <p className="text-sm text-muted-foreground">{__('imports.no_layer')}</p>
                ) : null}
                {record.status === 'completed' && !layerId ? (
                    <p className="text-sm text-muted-foreground">{__('imports.finished_no_layer')}</p>
                ) : null}
                {record.error_message ? <p className="text-sm text-destructive">{record.error_message}</p> : null}
                <dl>
                    <Fact label={t('forms.type')} value={String(record.file_type || '').toUpperCase() || t('common.em_dash')} />
                    <Fact label={__('imports.size')} value={bytes(record.file_size)} />
                    <Fact label={t('layers.geometry')} value={record.geometry_type || t('common.em_dash')} />
                    <Fact label={t('layers.features')} value={record.feature_count ? count(record.feature_count) : t('common.em_dash')} />
                    <Fact label={t('layers.added')} value={when(record.created_at)} />
                    <Fact label={t('common.finished')} value={when(record.completed_at)} />
                </dl>
                {layerId ? (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {__('imports.next_hint')}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <PrimaryLink href={`/layers/${layerId}`}>{__('imports.open_layer')}</PrimaryLink>
                            <GhostLink href={`/layers/${layerId}/attributes`}>{__('imports.review_features')}</GhostLink>
                            <GhostLink href={`/maps/builder?layer=${layerId}`}>{__('imports.open_in_map')}</GhostLink>
                        </div>
                    </div>
                ) : record.status === 'failed' || record.status === 'completed' ? (
                    <div className="flex flex-wrap gap-2">
                        <PrimaryLink href="/imports/create">{__('imports.import_another')}</PrimaryLink>
                        <GhostLink href="/layers">{__('dashboard.open_layers')}</GhostLink>
                    </div>
                ) : null}
            </Panel>
        </AppLayout>
    );
}
