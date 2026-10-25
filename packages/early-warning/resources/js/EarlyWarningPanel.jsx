import { useEffect, useMemo, useState } from 'react';
import { CloudRain, X } from 'lucide-react';

const LEVEL_COLORS = {
    green: '#22c55e',
    yellow: '#eab308',
    orange: '#f97316',
    red: '#ef4444',
};

const LEVEL_RANK = { red: 4, orange: 3, yellow: 2, green: 1 };

/**
 * Map-builder panel (#62): active basin warnings + forecast-hour timeline.
 * Advisory wording only — decision support, not an official warning.
 */
export default function EarlyWarningPanel({ onClose }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [hourIndex, setHourIndex] = useState(0);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoading(true);
            setError(null);
            try {
                const res = await fetch('/x/early-warning/warnings?hours=72', {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (res.status === 404) {
                    throw new Error('Early Warning is not enabled for this organization.');
                }
                if (!res.ok) {
                    throw new Error(`Could not load warnings (${res.status})`);
                }
                const json = await res.json();
                if (!cancelled) {
                    setData(json);
                    setHourIndex(0);
                }
            } catch (err) {
                if (!cancelled) {
                    setError(err.message || 'Failed to load warnings');
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
    }, []);

    const hours = data?.hours ?? [];
    const selectedHour = hours[hourIndex] ?? null;

    const basinsAtHour = useMemo(() => {
        const list = data?.basins ?? [];
        return [...list]
            .map((basin) => {
                const point = basin.timeline?.[hourIndex] ?? {
                    risk_level: basin.risk_level,
                    score: basin.score,
                    precip_mm: basin.precip_mm,
                };
                return { ...basin, ...point };
            })
            .sort((a, b) => {
                const ra = LEVEL_RANK[a.risk_level] ?? 0;
                const rb = LEVEL_RANK[b.risk_level] ?? 0;
                if (ra !== rb) {
                    return rb - ra;
                }
                return (b.score ?? 0) - (a.score ?? 0);
            });
    }, [data, hourIndex]);

    const activeCount = basinsAtHour.filter((b) => (LEVEL_RANK[b.risk_level] ?? 0) >= 2).length;

    const formatHour = (iso) => {
        if (!iso) {
            return '—';
        }
        try {
            return new Intl.DateTimeFormat(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                timeZone: 'UTC',
                timeZoneName: 'short',
            }).format(new Date(iso));
        } catch {
            return iso;
        }
    };

    return (
        <div className="analysis-panel early-warning-panel">
            <div className="panel-header">
                <h4>
                    <CloudRain className="glyph me-1" strokeWidth={1.75} aria-hidden="true" />
                    Early Warning
                </h4>
                <button type="button" onClick={onClose} className="close-btn" aria-label="Close">
                    <X className="glyph" strokeWidth={1.75} aria-hidden="true" />
                </button>
            </div>
            <div className="panel-body">
                <p className="ew-advisory small mb-3" role="note">
                    {data?.advisory ??
                        'Forecast-based decision support only — not an official warning.'}
                    {data?.authority?.name ? (
                        <>
                            {' '}
                            For official guidance, consult{' '}
                            {data.authority.url ? (
                                <a
                                    href={data.authority.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {data.authority.name}
                                </a>
                            ) : (
                                data.authority.name
                            )}
                            .
                        </>
                    ) : null}
                </p>

                {loading && <p className="text-muted small">Loading basin risk…</p>}
                {error && <div className="alert alert-danger py-2 small mb-3">{error}</div>}

                {!loading && !error && data && (
                    <>
                        <div className="ew-timeline mb-3">
                            <div className="d-flex justify-content-between align-items-baseline mb-1">
                                <label htmlFor="ew-hour-slider" className="small mb-0 fw-semibold">
                                    Forecast hour
                                </label>
                                <span className="small text-muted">{formatHour(selectedHour)}</span>
                            </div>
                            <input
                                id="ew-hour-slider"
                                type="range"
                                className="form-range"
                                min={0}
                                max={Math.max(0, hours.length - 1)}
                                step={1}
                                value={hourIndex}
                                onChange={(e) => setHourIndex(Number(e.target.value))}
                                aria-valuetext={formatHour(selectedHour)}
                            />
                            <div className="d-flex justify-content-between small text-muted">
                                <span>Now</span>
                                <span>+{Math.max(0, hours.length - 1)}h</span>
                            </div>
                        </div>

                        <p className="small text-muted mb-2">
                            {activeCount > 0
                                ? `${activeCount} basin${activeCount === 1 ? '' : 's'} at yellow or above`
                                : 'No elevated risk for this hour'}
                        </p>

                        <ul className="ew-basin-list list-unstyled mb-0">
                            {basinsAtHour.length === 0 && (
                                <li className="text-muted small">No subscribed basins.</li>
                            )}
                            {basinsAtHour.map((basin) => (
                                <li key={basin.basin_code} className="ew-basin-row">
                                    <span
                                        className="ew-level-dot"
                                        style={{ background: LEVEL_COLORS[basin.risk_level] || '#94a3b8' }}
                                        title={basin.risk_level}
                                        aria-hidden="true"
                                    />
                                    <div className="ew-basin-meta">
                                        <div className="ew-basin-name">{basin.name}</div>
                                        <div className="small text-muted">{basin.basin_code}</div>
                                    </div>
                                    <div className="ew-basin-stats small text-end">
                                        <div className="text-capitalize">{basin.risk_level}</div>
                                        <div className="text-muted">
                                            {basin.precip_mm != null ? `${basin.precip_mm.toFixed(1)} mm` : '—'}
                                            {basin.score != null ? ` · ${basin.score.toFixed(0)}` : ''}
                                        </div>
                                        {(basin.buildings != null || basin.road_km != null) && (
                                            <div className="text-muted">
                                                {basin.buildings != null ? `${basin.buildings} bld` : '—'}
                                                {basin.road_km != null ? ` · ${basin.road_km.toFixed(1)} km` : ''}
                                            </div>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </div>
    );
}
