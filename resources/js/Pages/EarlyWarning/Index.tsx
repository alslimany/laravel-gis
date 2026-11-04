import { Link, usePage } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, PageHeader, Panel, PrimaryLink } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { when } from '@/lib/format';
import AdvisoryBanner from './AdvisoryBanner';

type BasinRisk = {
    basin_code: string;
    name: string;
    name_ar?: string | null;
    area_km2?: number | null;
    risk_level: string;
    score?: number | null;
    forecast_at?: string | null;
    buildings?: number | null;
    road_km?: number | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    basin_count: number;
    forecast_rows: number;
    latest_fetch_at?: string | null;
    subscriptions: number;
    basins?: BasinRisk[];
    mapBuilderUrl?: string;
    alertRulesUrl?: string;
    warningsUrl?: string;
};

const LEVEL_CLASS: Record<string, string> = {
    green: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    yellow: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    orange: 'bg-orange-500/15 text-orange-800 dark:text-orange-300',
    red: 'bg-red-500/15 text-red-700 dark:text-red-400',
};

export default function Index({
    advisory,
    authority = null,
    basin_count,
    forecast_rows,
    latest_fetch_at,
    subscriptions,
    basins = [],
    mapBuilderUrl = '/maps/builder',
    alertRulesUrl = '/x/early-warning/alert-rules',
    warningsUrl,
}: Props) {
    const { t } = useTranslation();
    const isSuperAdmin = Boolean((usePage().props as { user?: { is_super_admin?: boolean } | null }).user?.is_super_admin);
    return (
        <AppLayout
            title={t('early_warning.title')}
            breadcrumbs={[{ title: t('early_warning.title'), href: '/x/early-warning' }]}
        >
            <PageHeader
                title={t('early_warning.title')}
                action={
                    <div className="flex flex-wrap gap-2">
                        {warningsUrl ? <PrimaryLink href={warningsUrl}>{t('ew.warnings.open')}</PrimaryLink> : null}
                        <PrimaryLink href={alertRulesUrl}>{t('early_warning.alert_rules')}</PrimaryLink>
                        <PrimaryLink href={mapBuilderUrl}>{t('maps.open_builder')}</PrimaryLink>
                    </div>
                }
            />

            <AdvisoryBanner advisory={advisory} authority={authority} />

            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label={t('early_warning.basins')} value={String(basin_count)} />
                <Stat label={t('early_warning.subscriptions')} value={String(subscriptions)} />
                <Stat label={t('early_warning.forecast_rows')} value={String(forecast_rows)} />
                <Stat label={t('early_warning.last_fetch')} value={latest_fetch_at ? when(latest_fetch_at) : t('common.em_dash')} />
            </div>

            <Panel>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('early_warning.current_basin_risk')}</h2>
                    <Link href={mapBuilderUrl} className="text-sm font-medium text-primary hover:underline">
                        {__('ew.timeline_in_builder')}
                    </Link>
                </div>

                {basins.length === 0 ? (
                    <Empty>
                        {__('ew.no_basins')}
                        {isSuperAdmin ? ` ${__('ew.no_basins_ops')}` : null}
                    </Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {basins.map((basin) => (
                            <li
                                key={basin.basin_code}
                                className="flex flex-wrap items-center gap-3 px-3 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">{basin.name}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {basin.basin_code}
                                        {basin.area_km2 != null ? ` · ${basin.area_km2.toFixed(1)} km²` : ''}
                                    </div>
                                </div>
                                <Badge
                                    variant="secondary"
                                    className={`capitalize ${LEVEL_CLASS[basin.risk_level] ?? ''}`}
                                >
                                    {enumLabel(basin.risk_level)}
                                </Badge>
                                <span dir="ltr" className="w-16 text-end font-mono text-sm tabular-nums text-muted-foreground">
                                    {basin.score != null ? basin.score.toFixed(0) : t('common.em_dash')}
                                </span>
                                <span className="w-28 text-end text-xs text-muted-foreground">
                                    {basin.buildings != null || basin.road_km != null
                                        ? __('ew.exposure_summary', { buildings: basin.buildings ?? t('common.em_dash'), km: basin.road_km != null ? basin.road_km.toFixed(1) : t('common.em_dash') })
                                        : __('ew.no_exposure')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <Panel className="px-3 py-3">
            <p className="font-mono text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                {label}
            </p>
            <p dir="ltr" className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
        </Panel>
    );
}
