import { useEffect, useRef, useState } from 'react';
import {
    Chart,
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    ArcElement,
    PieController,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    ArcElement,
    PieController,
    Tooltip,
    Legend
);

const COLORS = [
    '#0f766e',
    '#e74c3c',
    '#2ecc71',
    '#f39c12',
    '#9b59b6',
    '#1abc9c',
    '#34495e',
    '#e67e22',
];

function ChartWidget({ widget }) {
    const canvasRef = useRef(null);
    const chartRef = useRef(null);

    useEffect(() => {
        if (!canvasRef.current || !['bar', 'pie'].includes(widget.type) || widget.error) {
            return undefined;
        }
        if (chartRef.current) {
            chartRef.current.destroy();
        }
        chartRef.current = new Chart(canvasRef.current, {
            type: widget.type === 'pie' ? 'pie' : 'bar',
            data: {
                labels: widget.labels || [],
                datasets: [
                    {
                        label: widget.title,
                        data: widget.values || [],
                        backgroundColor: COLORS,
                    },
                ],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: widget.type === 'pie' } },
            },
        });
        return () => {
            chartRef.current?.destroy();
            chartRef.current = null;
        };
    }, [widget]);

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
                            ) : widget.type === 'kpi' ? (
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
