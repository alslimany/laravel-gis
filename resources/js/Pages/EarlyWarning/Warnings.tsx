import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Empty, PageHeader, Panel, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { enumLabel } from '@/lib/enum-labels';
import { whenTime } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import AdvisoryBanner from './AdvisoryBanner';

type TimelineRow = { forecast_at: string; risk_level: string; score?: number | null; precip_mm?: number | null };

type Basin = {
    basin_code: string;
    name: string;
    name_ar?: string | null;
    display_name: string;
    risk_level: string;
    score?: number | null;
    precip_mm?: number | null;
    buildings?: number | null;
    road_km?: number | null;
    peak_level: string;
    peak_at?: string | null;
    precip_total_mm: number;
    timeline: TimelineRow[];
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    generated_at: string;
    hours: string[];
    basins: Basin[];
    filters: { hours: number; level: string | null; q: string };
    hour_options: number[];
    levels: string[];
    index_url: string;
    warnings_url: string;
};

const RANK: Record<string, number> = { red: 4, orange: 3, yellow: 2, green: 1 };

const BADGE: Record<string, string> = {
    green: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    yellow: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    orange: 'bg-orange-500/15 text-orange-800 dark:text-orange-300',
    red: 'bg-red-500/15 text-red-700 dark:text-red-400',
};

const CELL: Record<string, string> = {
    green: 'bg-emerald-500/70',
    yellow: 'bg-amber-400',
    orange: 'bg-orange-500',
    red: 'bg-red-600',
};

function LevelBadge({ level }: { level: string }) {
    return (
        <Badge variant="secondary" className={BADGE[level] ?? ''}>
            {enumLabel(level, 'ew.risk_level')}
        </Badge>
    );
}

export default function Warnings({
    advisory,
    authority = null,
    generated_at,
    basins,
    filters,
    hour_options,
    levels,
    index_url,
    warnings_url,
}: Props) {
    const { t } = useTranslation();
    const [q, setQ] = useState(filters.q ?? '');
    const [level, setLevel] = useState(filters.level ?? '');

    const rows = useMemo(() => {
        const needle = q.trim().toLowerCase();
        const min = level ? RANK[level] ?? 0 : 0;

        return basins.filter((basin) => {
            if (min && (RANK[basin.peak_level] ?? 0) < min) {
                return false;
            }
            if (!needle) {
                return true;
            }

            return [basin.display_name, basin.name, basin.name_ar ?? '', basin.basin_code].some((value) =>
                value.toLowerCase().includes(needle),
            );
        });
    }, [basins, q, level]);

    function changeHours(next: string) {
        router.get(
            warnings_url,
            { hours: next, ...(level ? { level } : {}), ...(q ? { q } : {}) },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <AppLayout
            title={t('ew.warnings.title')}
            breadcrumbs={[
                { title: t('early_warning.title'), href: index_url },
                { title: t('ew.warnings.title'), href: warnings_url },
            ]}
        >
            <PageHeader
                title={t('ew.warnings.title')}
                action={
                    <Link href={index_url} className="text-sm font-medium text-primary hover:underline">
                        {t('early_warning.title')}
                    </Link>
                }
            />

            <p className="mb-3 mt-1 text-xs text-muted-foreground">
                {t('ew.warnings.generated', { time: whenTime(generated_at) })}
            </p>

            <AdvisoryBanner advisory={advisory} authority={authority} />

            <div className="mb-4 grid gap-3 sm:grid-cols-3" data-testid="warnings-filters">
                <label className="grid gap-1 text-sm">
                    <span className="text-muted-foreground">{t('ew.warnings.search')}</span>
                    <TextInput
                        value={q}
                        placeholder={t('ew.warnings.search_placeholder')}
                        onChange={(event) => setQ(event.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    <span className="text-muted-foreground">{t('ew.warnings.min_level')}</span>
                    <Select value={level} onChange={(event) => setLevel(event.target.value)}>
                        <option value="">{t('ew.warnings.all_levels')}</option>
                        {levels.map((value) => (
                            <option key={value} value={value}>
                                {enumLabel(value, 'ew.risk_level')}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span className="text-muted-foreground">{t('ew.warnings.horizon')}</span>
                    <Select value={String(filters.hours)} onChange={(event) => changeHours(event.target.value)}>
                        {hour_options.map((value) => (
                            <option key={value} value={value}>
                                {t('ew.warnings.next_hours', { count: value })}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>

            <Panel>
                {rows.length === 0 ? (
                    <Empty>{t('ew.warnings.empty')}</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 text-start font-medium">{t('ew.warnings.basin')}</th>
                                    <th className="px-3 py-2 text-start font-medium">{t('ew.warnings.now')}</th>
                                    <th className="px-3 py-2 text-start font-medium">{t('ew.warnings.peak')}</th>
                                    <th className="px-3 py-2 text-start font-medium">{t('ew.warnings.precip_total')}</th>
                                    <th className="px-3 py-2 text-start font-medium">{t('ew.warnings.exposure')}</th>
                                    <th className="px-3 py-2 text-start font-medium">{t('ew.warnings.timeline')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {rows.map((basin) => (
                                    <tr key={basin.basin_code} data-testid="warning-row">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">{basin.display_name}</div>
                                            <div dir="ltr" className="text-start font-mono text-xs text-muted-foreground">
                                                {basin.basin_code}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <LevelBadge level={basin.risk_level} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <LevelBadge level={basin.peak_level} />
                                            {basin.peak_at ? (
                                                <div className="mt-1 text-xs text-muted-foreground">{whenTime(basin.peak_at)}</div>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">
                                            {t('ew.warnings.mm', { value: basin.precip_total_mm })}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {basin.buildings != null || basin.road_km != null
                                                ? t('ew.exposure_summary', {
                                                      buildings: basin.buildings ?? 0,
                                                      km: basin.road_km ?? 0,
                                                  })
                                                : t('common.em_dash')}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex h-4 min-w-40 gap-px" aria-label={t('ew.warnings.timeline')}>
                                                {basin.timeline.map((row) => (
                                                    <span
                                                        key={row.forecast_at}
                                                        className={`h-full flex-1 rounded-[1px] ${CELL[row.risk_level] ?? 'bg-muted'}`}
                                                        title={`${whenTime(row.forecast_at)} · ${enumLabel(row.risk_level, 'ew.risk_level')}${
                                                            row.precip_mm != null ? ` · ${t('ew.warnings.mm', { value: row.precip_mm })}` : ''
                                                        }`}
                                                    />
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </AppLayout>
    );
}
