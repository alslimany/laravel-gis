import { FormEvent, useState } from 'react';
import { Head } from '@inertiajs/react';
import AdvisoryBanner from './AdvisoryBanner';

type Basin = {
    basin_code: string;
    name: string;
    risk_level: string;
    score?: number | null;
    outlet_lat?: number | null;
    outlet_lon?: number | null;
};

type Report = {
    id: number;
    latitude: number;
    longitude: number;
    note?: string | null;
    photo_url?: string | null;
    reported_at?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    token: string;
    feed_url: string;
    report_url: string;
    can_report: boolean;
    basins: Basin[];
    reports: Report[];
};

const LEVEL: Record<string, string> = {
    green: 'text-emerald-700',
    yellow: 'text-amber-700',
    orange: 'text-orange-700',
    red: 'text-red-700',
};

export default function Public({
    advisory,
    authority = null,
    report_url,
    can_report,
    basins,
    reports,
}: Props) {
    const [status, setStatus] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!can_report) {
            return;
        }
        setBusy(true);
        setStatus(null);
        const form = event.currentTarget;
        const body = new FormData(form);
        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
            );
            const res = await fetch(report_url, {
                method: 'POST',
                body,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                },
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(json.message || `Submit failed (${res.status})`);
            }
            setStatus('Report submitted for verification. Thank you.');
            form.reset();
        } catch (err) {
            setStatus(err instanceof Error ? err.message : 'Submit failed');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <Head title="Early Warning — public" />
            <main className="mx-auto max-w-3xl px-4 py-8">
                <h1 className="text-2xl font-semibold tracking-tight">Early Warning</h1>
                <AdvisoryBanner
                    advisory={advisory}
                    authority={authority}
                    className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900"
                />

                <section className="mt-8">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Basin risk</h2>
                    <ul className="mt-3 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                        {basins.map((basin) => (
                            <li key={basin.basin_code} className="flex items-center gap-3 px-3 py-2 text-sm">
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">{basin.name}</div>
                                    <div className="text-xs text-slate-500">{basin.basin_code}</div>
                                </div>
                                <span className={`capitalize ${LEVEL[basin.risk_level] ?? ''}`}>
                                    {basin.risk_level}
                                </span>
                                <span className="w-10 text-right font-mono text-xs text-slate-500">
                                    {basin.score != null ? basin.score.toFixed(0) : '—'}
                                </span>
                            </li>
                        ))}
                        {basins.length === 0 ? (
                            <li className="px-3 py-4 text-sm text-slate-500">No basins available.</li>
                        ) : null}
                    </ul>
                </section>

                <section className="mt-8">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Verified citizen reports
                    </h2>
                    <ul className="mt-3 space-y-2">
                        {reports.map((report) => (
                            <li key={report.id} className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm">
                                <div className="font-mono text-xs text-slate-500">
                                    {report.latitude.toFixed(4)}, {report.longitude.toFixed(4)}
                                </div>
                                {report.note ? <p className="mt-1">{report.note}</p> : null}
                                {report.photo_url ? (
                                    <a
                                        href={report.photo_url}
                                        className="mt-1 inline-block text-xs text-sky-700 hover:underline"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Photo
                                    </a>
                                ) : null}
                            </li>
                        ))}
                        {reports.length === 0 ? (
                            <li className="text-sm text-slate-500">No approved reports in the last 14 days.</li>
                        ) : null}
                    </ul>
                </section>

                {can_report ? (
                    <section className="mt-8 rounded-md border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold">Submit a field report</h2>
                        <p className="mt-1 text-xs text-slate-500">
                            Photo + location help verify conditions. Moderators review before public display.
                        </p>
                        <form onSubmit={submit} className="mt-4 space-y-3" encType="multipart/form-data">
                            <label className="block text-sm">
                                Latitude
                                <input
                                    name="latitude"
                                    type="number"
                                    step="any"
                                    required
                                    className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                                />
                            </label>
                            <label className="block text-sm">
                                Longitude
                                <input
                                    name="longitude"
                                    type="number"
                                    step="any"
                                    required
                                    className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                                />
                            </label>
                            <label className="block text-sm">
                                Note
                                <textarea
                                    name="note"
                                    rows={3}
                                    className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                                />
                            </label>
                            <label className="block text-sm">
                                Photo
                                <input name="photo" type="file" accept="image/*" className="mt-1 block w-full text-sm" />
                            </label>
                            <button
                                type="submit"
                                disabled={busy}
                                className="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-60"
                            >
                                {busy ? 'Sending…' : 'Submit report'}
                            </button>
                            {status ? <p className="text-sm text-slate-600">{status}</p> : null}
                        </form>
                    </section>
                ) : null}
            </main>
        </div>
    );
}
