import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { bytes, count, when } from '../../lib/format';
import { GhostLink, PageHeader, Pill } from '../../Components/ui';

const TONES = { completed: 'live', processing: 'run', failed: 'fail', pending: 'idle' };

export default function Show({ import: record }) {
    useEffect(() => {
        if (record.status !== 'processing' && record.status !== 'pending') {
            return undefined;
        }
        const timer = window.setInterval(() => {
            router.reload({ only: ['import'] });
        }, 3000);
        return () => window.clearInterval(timer);
    }, [record.status]);

    return (
        <AppLayout title={record.file_name}>
            <PageHeader title={record.file_name} action={<GhostLink href="/imports">Back to imports</GhostLink>} />
            <div className="mb-4">
                <Pill tone={TONES[record.status] || 'idle'}>{record.status}</Pill>
            </div>
            {record.status === 'processing' || record.status === 'pending' ? (
                <div className="mb-4 h-2 max-w-md overflow-hidden rounded bg-panel-2">
                    <div className="h-full bg-cyan" style={{ width: `${record.progress || 0}%` }} />
                </div>
            ) : null}
            {record.error_message ? <p className="mb-4 text-danger">{record.error_message}</p> : null}
            <dl className="grid max-w-xl gap-3">
                <div>
                    <dt className="text-muted">Type</dt>
                    <dd className="font-mono">{String(record.file_type || '').toUpperCase()}</dd>
                </div>
                <div>
                    <dt className="text-muted">Size</dt>
                    <dd className="font-mono">{bytes(record.file_size)}</dd>
                </div>
                <div>
                    <dt className="text-muted">Features</dt>
                    <dd className="font-mono">{record.feature_count ? count(record.feature_count) : '—'}</dd>
                </div>
                <div>
                    <dt className="text-muted">Created</dt>
                    <dd>{when(record.created_at)}</dd>
                </div>
            </dl>
        </AppLayout>
    );
}
