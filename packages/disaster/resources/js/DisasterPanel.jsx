import { useEffect, useState } from 'react';
import { useTranslation } from '@/lib/i18n';

export default function DisasterPanel({ onClose }) {
    const { t, locale } = useTranslation();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoading(true);
            setError(null);
            try {
                const res = await fetch('/x/disaster', {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (res.status === 404) {
                    throw new Error(t('disaster.panel.not_enabled'));
                }
                if (!res.ok) {
                    throw new Error(t('disaster.panel.load_error', { status: res.status }));
                }
                const json = await res.json();
                if (!cancelled) {
                    setData(json);
                }
            } catch (err) {
                if (!cancelled) {
                    setError(err.message || t('disaster.panel.load_failed'));
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
        // Re-fetch when locale changes so error strings match the UI language.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- t is recreated each render
    }, [locale]);

    const counts = data?.counts ?? {};

    return (
        <div className="analysis-panel disaster-panel p-2 text-sm">
            <div className="panel-header d-flex justify-content-between align-items-center mb-2">
                <div className="fw-semibold">{t('disaster.title')}</div>
                {onClose ? (
                    <button type="button" onClick={onClose} className="close-btn btn btn-sm btn-link">
                        {t('disaster.panel.close')}
                    </button>
                ) : null}
            </div>
            {loading && <p className="text-muted small">{t('disaster.panel.loading')}</p>}
            {error && <div className="alert alert-danger py-2 small">{error}</div>}
            {!loading && !error && data ? (
                <>
                    <p className="small text-muted mb-2">{data.advisory}</p>
                    <ul className="list-unstyled mb-0 small text-muted">
                        <li>
                            {t('disaster.medical_facilities')}: {counts.medical_facilities ?? 0}
                        </li>
                        <li>
                            {t('disaster.staging_areas')}: {counts.staging_areas ?? 0}
                        </li>
                        <li>
                            {t('disaster.critical_sites')}: {counts.critical_sites ?? 0}
                        </li>
                        <li>
                            {t('disaster.open_incidents')}: {counts.open_incidents ?? 0}
                        </li>
                        <li>
                            {t('disaster.damage_points')}: {counts.damage_points ?? 0}
                        </li>
                        <li>
                            {t('disaster.avl_units')}: {counts.avl_units ?? 0}
                        </li>
                    </ul>
                </>
            ) : null}
        </div>
    );
}
