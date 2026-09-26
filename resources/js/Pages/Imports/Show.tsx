import { useEffect } from 'react';
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
            <dd className="text-sm tabular-nums">{value}</dd>
        </div>
    );
}

export default function Show({ import: record }: { import: ImportRecord }) {
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
            <PageHeader title={record.file_name} action={<GhostLink href="/imports">Back to imports</GhostLink>} />
            <Panel className="max-w-xl space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Pill tone={TONES[record.status as keyof typeof TONES] || 'idle'}>{status}</Pill>
                    <span className="text-sm tabular-nums text-muted-foreground">{count(progress)}%</span>
                </div>
                <div
                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-valuenow={progress}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Import progress"
                >
                    <div
                        className="h-full bg-primary transition-[width] duration-500 ease-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                {active ? (
                    <p className="text-sm text-muted-foreground">
                        This page refreshes while the import runs. It stays here until the queue worker finishes the file.
                    </p>
                ) : null}
                {record.status === 'failed' ? (
                    <p className="text-sm text-muted-foreground">This import did not create a layer. Try another file.</p>
                ) : null}
                {record.status === 'completed' && !layerId ? (
                    <p className="text-sm text-muted-foreground">The file finished without a layer.</p>
                ) : null}
                {record.error_message ? <p className="text-sm text-destructive">{record.error_message}</p> : null}
                <dl>
                    <Fact label="Type" value={String(record.file_type || '').toUpperCase() || '—'} />
                    <Fact label="Size" value={bytes(record.file_size)} />
                    <Fact label="Geometry" value={record.geometry_type || '—'} />
                    <Fact label="Features" value={record.feature_count ? count(record.feature_count) : '—'} />
                    <Fact label="Added" value={when(record.created_at)} />
                    <Fact label="Finished" value={when(record.completed_at)} />
                </dl>
                {layerId ? (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Next: open the layer and publish it, then style it and add it to a map.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <PrimaryLink href={`/layers/${layerId}`}>Open layer</PrimaryLink>
                            <GhostLink href={`/layers/${layerId}/attributes`}>Review features</GhostLink>
                            <GhostLink href={`/maps/builder?layer=${layerId}`}>Open in map</GhostLink>
                        </div>
                    </div>
                ) : record.status === 'failed' || record.status === 'completed' ? (
                    <div className="flex flex-wrap gap-2">
                        <PrimaryLink href="/imports/create">Import another file</PrimaryLink>
                        <GhostLink href="/layers">Open layers</GhostLink>
                    </div>
                ) : null}
            </Panel>
        </AppLayout>
    );
}
