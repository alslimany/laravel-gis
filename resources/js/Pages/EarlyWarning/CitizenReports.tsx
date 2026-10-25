import { Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/format';
import AdvisoryBanner from './AdvisoryBanner';

type Report = {
    id: number;
    latitude: number;
    longitude: number;
    note?: string | null;
    basin_code?: string | null;
    status: string;
    source: string;
    photo_url?: string | null;
    reported_at?: string | null;
};

type TokenRow = {
    id: number;
    label?: string | null;
    abilities: string[];
    revoked: boolean;
    last_used_at?: string | null;
    created_at?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    reports: Report[];
    tokens: TokenRow[];
    plain_token?: string | null;
    public_base: string;
    issue_token_url: string;
};

export default function CitizenReports({
    advisory,
    authority = null,
    reports,
    tokens,
    plain_token = null,
    public_base,
    issue_token_url,
}: Props) {
    const tokenForm = useForm({ label: 'Public feed' });

    function issueToken(event: React.FormEvent) {
        event.preventDefault();
        tokenForm.post(issue_token_url, { preserveScroll: true });
    }

    return (
        <AppLayout
            title="Citizen reports"
            breadcrumbs={[
                { title: 'Early Warning', href: '/x/early-warning' },
                { title: 'Citizen reports', href: '/x/early-warning/citizen-reports' },
            ]}
        >
            <PageHeader title="Citizen reports" />
            <AdvisoryBanner
                advisory={advisory}
                authority={authority}
                className="mb-6 max-w-2xl text-sm text-muted-foreground"
            />

            {plain_token ? (
                <Panel className="mb-6 border-amber-500/40 bg-amber-500/5 p-3">
                    <p className="text-sm font-medium">Copy this public token now</p>
                    <code className="mt-2 block break-all text-xs">{plain_token}</code>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Public URL: {public_base}/{plain_token}
                    </p>
                </Panel>
            ) : null}

            <div className="mb-8 grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">Public tokens</h2>
                    </div>
                    <form onSubmit={issueToken} className="flex flex-wrap items-end gap-2 p-3">
                        <div className="min-w-[12rem] flex-1">
                            <Field label="Label">
                                <TextInput
                                    value={tokenForm.data.label}
                                    onChange={(e) => tokenForm.setData('label', e.target.value)}
                                />
                            </Field>
                        </div>
                        <PrimaryButton type="submit" disabled={tokenForm.processing}>
                            Issue token
                        </PrimaryButton>
                    </form>
                    {tokens.length === 0 ? (
                        <Empty>No tokens yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-border">
                            {tokens.map((token) => (
                                <li key={token.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                    <span className="font-medium">{token.label || `Token #${token.id}`}</span>
                                    <Badge variant="secondary">{token.revoked ? 'Revoked' : 'Active'}</Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {token.abilities.join(', ') || 'feed, report'}
                                    </span>
                                    {!token.revoked ? (
                                        <Button asChild variant="ghost" size="sm" className="ms-auto">
                                            <Link
                                                href={`/x/early-warning/public-tokens/${token.id}`}
                                                method="delete"
                                                as="button"
                                                preserveScroll
                                            >
                                                Revoke
                                            </Link>
                                        </Button>
                                    ) : (
                                        <span className="ms-auto text-xs text-muted-foreground">
                                            {token.created_at ? when(token.created_at) : ''}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">Incoming reports</h2>
                    </div>
                    {reports.length === 0 ? (
                        <Empty>No citizen reports yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-border">
                            {reports.map((report) => (
                                <li key={report.id} className="px-3 py-3 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary" className="capitalize">
                                            {report.status}
                                        </Badge>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {report.latitude.toFixed(4)}, {report.longitude.toFixed(4)}
                                        </span>
                                        <span className="ms-auto text-xs text-muted-foreground">
                                            {report.reported_at ? when(report.reported_at) : '—'}
                                        </span>
                                    </div>
                                    {report.note ? <p className="mt-1 text-muted-foreground">{report.note}</p> : null}
                                    {report.photo_url ? (
                                        <a
                                            href={report.photo_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-1 inline-block text-xs text-primary hover:underline"
                                        >
                                            Photo
                                        </a>
                                    ) : null}
                                    <div className="mt-2 flex gap-2">
                                        {(['approved', 'rejected', 'pending'] as const).map((status) => (
                                            <Button key={status} asChild variant="outline" size="sm">
                                                <Link
                                                    href={`/x/early-warning/citizen-reports/${report.id}`}
                                                    method="put"
                                                    data={{ status }}
                                                    as="button"
                                                    preserveScroll
                                                >
                                                    {status}
                                                </Link>
                                            </Button>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
