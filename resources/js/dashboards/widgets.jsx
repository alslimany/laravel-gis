import { useEffect, useMemo, useRef } from 'react';
import {
    Chart,
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    ArcElement,
    PieController,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import SharedMap from '../Components/SharedMap';
import { DataTable, Table, thClass, tdMono } from '@/components/gis';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    ArcElement,
    PieController,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    Legend,
    Filler,
);

function themeColors() {
    const styles = getComputedStyle(document.documentElement);
    const read = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
    const palette = [1, 2, 3, 4, 5].map((index) => read(`--chart-${index}`)).filter(Boolean);
    return {
        series: palette[0] || 'oklch(0.55 0.12 210)',
        muted: read('--muted-foreground', 'oklch(0.48 0.02 255)'),
        copy: read('--foreground', 'oklch(0.22 0.025 255)'),
        line: read('--border', 'oklch(0.90 0.01 250)'),
        palette: palette.length ? palette : ['oklch(0.55 0.12 210)'],
    };
}

function ChartCanvas({ widget, style = 'bar' }) {
    const canvas = useRef(null);
    const chartRef = useRef(null);

    useEffect(() => {
        if (!canvas.current || widget.error) return undefined;
        const colors = themeColors();
        if (chartRef.current) chartRef.current.destroy();
        chartRef.current = new Chart(canvas.current, {
            type: style === 'pie' ? 'pie' : style === 'line' ? 'line' : 'bar',
            data: {
                labels: widget.labels || [],
                datasets: [
                    {
                        label: widget.title,
                        data: widget.values || [],
                        backgroundColor: style === 'pie' ? colors.palette : colors.series,
                        borderColor: colors.series,
                        borderWidth: style === 'line' ? 2 : 0,
                        fill: style === 'line',
                        tension: 0.3,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: style === 'pie',
                        labels: { color: colors.copy },
                    },
                },
                scales:
                    style === 'pie'
                        ? {}
                        : {
                              x: { ticks: { color: colors.muted }, grid: { color: colors.line } },
                              y: { ticks: { color: colors.muted }, grid: { color: colors.line } },
                          },
            },
        });
        return () => {
            chartRef.current?.destroy();
            chartRef.current = null;
        };
    }, [widget, style]);

    return <canvas ref={canvas} />;
}

function Note({ tone = 'muted', children }) {
    const className = tone === 'danger' ? 'text-sm text-destructive' : 'text-sm text-muted-foreground';
    return <p className={className}>{children}</p>;
}

export function WidgetFrame({ title, children, className = '', onClick, selected = false, actions = null }) {
    return (
        <section
            onClick={onClick}
            className={`flex h-full flex-col overflow-hidden rounded-lg border bg-card ${selected ? 'border-primary ring-2 ring-primary/30' : 'border-border'} ${className}`}
        >
            <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <h3 className="truncate text-sm font-semibold">{title}</h3>
                {actions}
            </div>
            <div className="min-h-0 flex-1 overflow-auto p-3">{children}</div>
        </section>
    );
}

export function IndicatorView({ widget }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (widget.status === 'empty') return <Note>{widget.message || 'Choose a layer when this widget should show data.'}</Note>;
    const display = widget.value == null || widget.value === '' ? '—' : widget.value;
    return (
        <p className="flex h-full items-end pb-1 text-3xl font-semibold tabular-nums text-foreground">
            {widget.prefix ? <span className="mr-1 text-base font-medium text-muted-foreground">{widget.prefix}</span> : null}
            <span>{display}</span>
            {widget.suffix ? <span className="ml-1 text-base font-medium text-muted-foreground">{widget.suffix}</span> : null}
        </p>
    );
}

export function SerialView({ widget }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (widget.status === 'empty' || !(widget.labels || []).length) {
        return <Note>{widget.message || 'No features in this layer.'}</Note>;
    }
    return (
        <div className="h-full min-h-[180px]">
            <ChartCanvas widget={widget} style={widget.chart_style === 'line' ? 'line' : 'bar'} />
        </div>
    );
}

export function PieView({ widget }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (widget.status === 'empty' || !(widget.labels || []).length) {
        return <Note>{widget.message || 'No features in this layer.'}</Note>;
    }
    return (
        <div className="h-full min-h-[180px]">
            <ChartCanvas widget={widget} style="pie" />
        </div>
    );
}

export function TableView({ widget }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (widget.status === 'empty') return <Note>{widget.message || 'Choose a layer when this widget should show data.'}</Note>;
    const labels = widget.labels || [];
    const rows = widget.rows || [];
    if (!labels.length) return <Note>{widget.message || 'Choose columns when this table should show attributes.'}</Note>;
    if (!rows.length) return <Note>{widget.message || 'No features in this layer.'}</Note>;
    return (
        <DataTable>
            <Table.Header>
                <Table.Row>
                    {labels.map((col) => (
                        <Table.Head key={col} className={`${thClass} sticky top-0 z-[1]`}>
                            {col}
                        </Table.Head>
                    ))}
                </Table.Row>
            </Table.Header>
            <Table.Body>
                {rows.map((row, index) => (
                    <Table.Row key={index}>
                        {labels.map((col) => (
                            <Table.Cell key={col} className={tdMono}>
                                {row[col] == null ? '' : String(row[col])}
                            </Table.Cell>
                        ))}
                    </Table.Row>
                ))}
            </Table.Body>
        </DataTable>
    );
}

export function ListView({ widget }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (widget.status === 'empty') return <Note>{widget.message || 'Choose a layer when this widget should show data.'}</Note>;
    const rows = widget.rows || [];
    if (!rows.length) return <Note>{widget.message || 'No features in this layer.'}</Note>;
    return (
        <ul className="space-y-2">
            {rows.map((row, index) => (
                <li key={index} className="border-b border-border pb-2 last:border-b-0">
                    <p className="font-medium">{row.title || '—'}</p>
                    {row.description ? <p className="text-sm text-muted-foreground">{row.description}</p> : null}
                </li>
            ))}
        </ul>
    );
}

export function MapView({ widget }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (!widget.map) {
        return <Note>{widget.message || 'Choose a layer or a saved map when this widget should show a map.'}</Note>;
    }
    return (
        <div className="flex h-full min-h-[220px] flex-col gap-2">
            {widget.source === 'map' && widget.map.name ? (
                <p className="truncate text-sm text-muted-foreground">{widget.map.name}</p>
            ) : null}
            <div className="min-h-[200px] flex-1 overflow-hidden rounded-md border border-border">
                <SharedMap map={widget.map} />
            </div>
        </div>
    );
}

export function TextView({ widget }) {
    return (
        <div className="space-y-2">
            {widget.body ? (
                <p className="whitespace-pre-wrap text-sm text-muted-foreground">{widget.body}</p>
            ) : (
                <p className="text-sm text-muted-foreground">Add a note in the inspector.</p>
            )}
        </div>
    );
}

export function CategoryView({ widget, value, onChange, interactive = false }) {
    if (widget.error) return <Note tone="danger">{widget.error}</Note>;
    if (widget.status === 'empty') return <Note>{widget.message}</Note>;
    const options = widget.options || widget.labels || [];
    if (!interactive) {
        return <p className="text-sm text-muted-foreground">{options.length ? `${options.length} values` : 'Choose a field'}</p>;
    }
    if (!options.length) return <Note>{widget.message || 'No values in this field yet.'}</Note>;
    return (
        <label className="block">
            <span className="sr-only">{widget.title}</span>
            <select
                className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm text-foreground"
                value={value ?? ''}
                onChange={(event) => onChange?.(event.target.value || null)}
            >
                <option value="">All</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </label>
    );
}

export function WidgetBody({ widget, filters, onFilterChange, interactiveFilters = false }) {
    const type = widget.type === 'kpi' ? 'indicator' : widget.type === 'bar' || widget.type === 'line' ? 'serial' : widget.type;

    if (type === 'indicator') return <IndicatorView widget={widget} />;
    if (type === 'serial') return <SerialView widget={{ ...widget, chart_style: widget.chart_style || (widget.type === 'line' ? 'line' : 'bar') }} />;
    if (type === 'pie') return <PieView widget={widget} />;
    if (type === 'table') return <TableView widget={widget} />;
    if (type === 'list') return <ListView widget={widget} />;
    if (type === 'map') return <MapView widget={widget} />;
    if (type === 'text') return <TextView widget={widget} />;
    if (type === 'category') {
        const layerKey = String(widget.layer_id ?? '');
        return (
            <CategoryView
                widget={widget}
                interactive={interactiveFilters}
                value={filters?.[layerKey] ?? null}
                onChange={(next) => onFilterChange?.(layerKey, next)}
            />
        );
    }
    return <p className="text-muted">Unknown widget.</p>;
}

export function mergeWidgetData(configWidgets, dataWidgets) {
    const byId = Object.fromEntries((dataWidgets || []).map((item) => [item.id, item]));
    return (configWidgets || []).map((config, index) => {
        const data = byId[config.id] || dataWidgets?.[index] || {};
        return {
            ...config,
            ...data,
            id: config.id,
            type: config.type,
            title: config.title,
            layout: config.layout || data.layout,
            chart_style: config.chart_style || data.chart_style,
            prefix: config.prefix ?? data.prefix,
            suffix: config.suffix ?? data.suffix,
            body: config.body ?? data.body,
            layer_id: config.layer_id ?? data.layer_id,
            map_id: data.map_id ?? config.map_id ?? null,
            source: data.source ?? config.source ?? null,
            status: data.status,
            message: data.message ?? null,
            error: data.error ?? null,
            map: data.map ?? null,
        };
    });
}

export function usePreviewWidgets(widgets, previewUrl, filters = {}) {
    const payload = useMemo(() => JSON.stringify({ widgets, filters }), [widgets, filters]);
    const cache = useRef({ key: '', data: [] });

    return { payload, cache };
}
