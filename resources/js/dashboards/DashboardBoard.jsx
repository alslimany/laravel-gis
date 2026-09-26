import { useEffect, useRef, useState } from 'react';
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
import { themeColors } from './chartTheme';

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

function chartStyle(widget) {
    if (widget.type === 'pie') return 'pie';
    if (widget.type === 'line' || widget.chart_style === 'line') return 'line';
    if (widget.type === 'bar' || widget.type === 'serial') return 'bar';
    return null;
}

function ChartWidget({ widget }) {
    const canvasRef = useRef(null);
    const chartRef = useRef(null);
    const style = chartStyle(widget);

    useEffect(() => {
        if (!canvasRef.current || !style || widget.error || widget.status === 'empty') {
            return undefined;
        }
        const colors = themeColors();
        if (chartRef.current) {
            chartRef.current.destroy();
        }
        chartRef.current = new Chart(canvasRef.current, {
            type: style === 'pie' ? 'pie' : style === 'line' ? 'line' : 'bar',
            data: {
                labels: widget.labels || [],
                datasets: [
                    {
                        label: widget.title,
                        data: widget.values || [],
                        backgroundColor: style === 'line' ? colors.series : colors.palette,
                        borderColor: colors.series,
                        borderWidth: style === 'line' ? 2 : 0,
                        fill: style === 'line',
                        tension: 0.3,
                    },
                ],
            },
            options: {
                responsive: true,
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

    return <canvas ref={canvasRef} height={180} />;
}

export default function DashboardBoard({ dataUrl }) {
    const [widgets, setWidgets] = useState([]);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const response = await fetch(dataUrl, {
                    headers: { Accept: 'application/json' },
                });
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to load widgets');
                }
                if (!cancelled) {
                    setWidgets(data.widgets || []);
                }
            } catch (err) {
                if (!cancelled) {
                    setError(err.message);
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [dataUrl]);

    if (loading) {
        return <p className="text-muted">Loading widgets…</p>;
    }
    if (error) {
        return <div className="alert alert-danger">{error}</div>;
    }
    if (!widgets.length) {
        return <div className="alert alert-info">No widgets configured.</div>;
    }

    return (
        <div className="row g-3">
            {widgets.map((widget) => (
                <div className="col-md-6 col-xl-4" key={widget.index ?? widget.title}>
                    <div className="card h-100">
                        <div className="card-header">{widget.title}</div>
                        <div className="card-body">
                            {widget.error ? (
                                <div className="text-danger small">{widget.error}</div>
                            ) : widget.status === 'empty' ? (
                                <div className="text-muted small">{widget.message}</div>
                            ) : widget.type === 'kpi' || widget.type === 'indicator' ? (
                                <div className="display-6">{widget.value ?? '—'}</div>
                            ) : widget.type === 'table' ? (
                                <div className="table-responsive" style={{ maxHeight: 260 }}>
                                    <table className="table table-sm">
                                        <thead>
                                            <tr>
                                                {(widget.labels || []).map((col) => (
                                                    <th key={col}>{col}</th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(widget.rows || []).map((row, i) => (
                                                <tr key={i}>
                                                    {(widget.labels || []).map((col) => (
                                                        <td key={col}>
                                                            {typeof row[col] === 'object'
                                                                ? JSON.stringify(row[col])
                                                                : String(row[col] ?? '')}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <ChartWidget widget={widget} />
                            )}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}
