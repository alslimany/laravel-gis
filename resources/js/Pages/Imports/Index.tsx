import { Link } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { bytes, count, when } from '@/lib/format';
import { ActionLink, DangerButton, Empty, PageHeader, Pager, Panel, Pill, PrimaryLink, RowActions, Table, destroyResource, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

const TONES = {
    completed: 'live',
    processing: 'run',
    failed: 'fail',
    pending: 'idle',
} as const;

const STATUS = {
    completed: 'Completed',
    processing: 'Processing',
    failed: 'Failed',
    pending: 'Pending',
} as const;

type ImportRow = {
    id: number;
    file_name: string;
    file_type?: string | null;
    file_size?: number | null;
    status: keyof typeof STATUS | string;
    progress?: number | null;
    created_at?: string | null;
};

export default function Index({ imports }: { imports?: { data?: ImportRow[]; links?: Array<{ url: string | null; label: string; active: boolean }> } }) {
    const { t } = useTranslation();
    const rows = imports?.data || [];

    return (
        <AppLayout title={t('imports.title')}>
            <PageHeader
                title={t('imports.title')}
                action={<PrimaryLink href="/imports/create">{__('imports.new_import')}</PrimaryLink>}
            />
            <Panel>
                {rows.length === 0 ? (
                    <Empty>
                        {__('imports.none_yet')}{' '}
                        <Link href="/imports/create" className="font-medium text-primary hover:underline">
                            {__('dashboard.import_dataset')}
                        </Link>
                    </Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{__('imports.file')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{t('forms.type')}</Table.Head>
                                <Table.Head className={`${thClass} hidden lg:table-cell`}>{__('imports.size')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.status')}</Table.Head>
                                <Table.Head className={`${thClass} hidden md:table-cell`}>{__('imports.progress')}</Table.Head>
                                <Table.Head className={`${thClass} hidden lg:table-cell`}>{t('layers.added')}</Table.Head>
                                <Table.Head className={thClass}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((item) => {
                                const status = item.status in STATUS ? STATUS[item.status as keyof typeof STATUS] : item.status;
                                const progress = Math.min(100, Math.max(0, Number(item.progress) || 0));

                                return (
                                    <Table.Row key={item.id}>
                                        <Table.Cell className={tdClass}>
                                            <Link href={`/imports/${item.id}`} className="font-medium text-primary hover:underline">
                                                {item.file_name}
                                            </Link>
                                            <p className="mt-0.5 text-xs text-muted-foreground md:hidden">
                                                {String(item.file_type || '').toUpperCase()} · {bytes(item.file_size)}
                                            </p>
                                        </Table.Cell>
                                        <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{String(item.file_type || '').toUpperCase() || t('common.em_dash')}</Table.Cell>
                                        <Table.Cell className={`${tdMono} hidden lg:table-cell`}>{bytes(item.file_size)}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            <Pill tone={TONES[item.status as keyof typeof TONES] || 'idle'}>{status}</Pill>
                                        </Table.Cell>
                                        <Table.Cell className={`${tdClass} hidden md:table-cell`}>
                                            <div className="flex min-w-28 items-center gap-2">
                                                <div
                                                    className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                                    role="progressbar"
                                                    aria-valuenow={progress}
                                                    aria-valuemin={0}
                                                    aria-valuemax={100}
                                                    aria-label={`${item.file_name} progress`}
                                                >
                                                    <div className="h-full bg-primary" style={{ width: `${progress}%` }} />
                                                </div>
                                                <span dir="ltr" className="w-9 text-end text-xs tabular-nums text-muted-foreground">{count(progress)}%</span>
                                            </div>
                                        </Table.Cell>
                                        <Table.Cell className={`${tdMuted} hidden lg:table-cell`}>{when(item.created_at)}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            <RowActions>
                                                <ActionLink href={`/imports/${item.id}`}>{t('actions.view')}</ActionLink>
                                                {item.status === 'failed' || item.status === 'completed' ? (
                                                    <DangerButton onClick={() => destroyResource(`/imports/${item.id}`, 'Delete this import?')}>{t('actions.delete')}</DangerButton>
                                                ) : null}
                                            </RowActions>
                                        </Table.Cell>
                                    </Table.Row>
                                );
                            })}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={imports} />
            </Panel>
        </AppLayout>
    );
}
