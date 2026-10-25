import { Link } from '@inertiajs/react';
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
}: Props) {
    return (
        <AppLayout
            title="Early Warning"
            breadcrumbs={[{ title: 'Early Warning', href: '/x/early-warning' }]}
        >
            <PageHeader
                title="Early Warning"
                action={
                    <div className="flex flex-wrap gap-2">
                        <PrimaryLink href={alertRulesUrl}>Alert rules</PrimaryLink>
                        <PrimaryLink href={mapBuilderUrl}>Open map builder</PrimaryLink>
                    </div>
                }
            />

            <AdvisoryBanner advisory={advisory} authority={authority} />

            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Basins" value={String(basin_count)} />
                <Stat label="Subscriptions" value={String(subscriptions)} />
                <Stat label="Forecast rows" value={String(forecast_rows)} />
                <Stat label="Last fetch" value={latest_fetch_at ? when(latest_fetch_at) : '—'} />
            </div>

            <Panel>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">Current basin risk</h2>
                    <Link href={mapBuilderUrl} className="text-sm font-medium text-primary hover:underline">
                        Timeline in map builder
                    </Link>
                </div>

                {basins.length === 0 ? (
                    <Empty>No basins linked yet. Run early-warning:link-basins, then refresh.</Empty>
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
                                    {basin.risk_level}
                                </Badge>
                                <span className="w-16 text-right font-mono text-sm tabular-nums text-muted-foreground">
                                    {basin.score != null ? basin.score.toFixed(0) : '—'}
                                </span>
                                <span className="w-28 text-right text-xs text-muted-foreground">
                                    {basin.buildings != null || basin.road_km != null
                                        ? `${basin.buildings ?? '—'} bld · ${basin.road_km != null ? basin.road_km.toFixed(1) : '—'} km`
                                        : 'no exposure'}
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
            <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
        </Panel>
    );
}
