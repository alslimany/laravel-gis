import { Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/format';
import AdvisoryBanner from './AdvisoryBanner';

type Rule = {
    id: number;
    name: string;
    basin_code: string | null;
    metric: string;
    operator: string;
    threshold: number;
    risk_level_min: string | null;
    channels: string[];
    enabled: boolean;
};

type Basin = { basin_code: string; name: string };

type LogRow = {
    id: number;
    basin_code: string;
    channel: string;
    status: string;
    rule_name?: string | null;
    value?: number | null;
    threshold?: number | null;
    forecast_at?: string | null;
    created_at?: string | null;
};

type ChannelSettings = {
    notify_emails: string;
    telegram_chat_ids: string;
    notify_phones: string;
    telegram_configured: boolean;
    sms_webhook_configured: boolean;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    calibration_note: string;
    rules: Rule[];
    basins: Basin[];
    metrics: string[];
    operators: string[];
    risk_levels: string[];
    recent_logs: LogRow[];
    channel_settings: ChannelSettings;
    store_url: string;
    seed_url: string;
    channels_url: string;
};

export default function AlertRules({
    advisory,
    authority = null,
    calibration_note,
    rules,
    basins,
    metrics,
    operators,
    risk_levels,
    recent_logs,
    channel_settings,
    store_url,
    seed_url,
    channels_url,
}: Props) {
    const form = useForm({
        name: '',
        basin_code: '',
        metric: 'precip_6h_mm',
        operator: '>=',
        threshold: 20,
        risk_level_min: 'yellow',
        enabled: true,
        channels: ['log'] as string[],
    });

    const channelsForm = useForm({
        notify_emails: channel_settings.notify_emails,
        telegram_chat_ids: channel_settings.telegram_chat_ids,
        notify_phones: channel_settings.notify_phones,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            basin_code: data.basin_code || null,
            risk_level_min: data.risk_level_min || null,
        }));
        form.post(store_url, { preserveScroll: true, onSuccess: () => form.reset('name') });
    }

    function saveChannels(event: React.FormEvent) {
        event.preventDefault();
        channelsForm.put(channels_url, { preserveScroll: true });
    }

    return (
        <AppLayout
            title="Alert rules"
            breadcrumbs={[
                { title: 'Early Warning', href: '/x/early-warning' },
                { title: 'Alert rules', href: '/x/early-warning/alert-rules' },
            ]}
        >
            <PageHeader
                title="Alert rules"
                action={
                    <Button asChild variant="outline" size="sm">
                        <Link href={seed_url} method="post" as="button" preserveScroll>
                            Seed Tripoli defaults
                        </Link>
                    </Button>
                }
            />

            <AdvisoryBanner
                advisory={advisory}
                authority={authority}
                className="mb-3 max-w-2xl rounded-md bg-muted/50 px-3 py-2 text-sm text-muted-foreground"
            />
            <p className="mb-6 max-w-2xl text-sm text-muted-foreground">{calibration_note}</p>

            <div className="mb-8 grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">Rules</h2>
                    </div>
                    {rules.length === 0 ? (
                        <Empty>No rules yet. Seed defaults or add one below.</Empty>
                    ) : (
                        <ul className="divide-y divide-border">
                            {rules.map((rule) => (
                                <li key={rule.id} className="flex flex-wrap items-start gap-3 px-3 py-3">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{rule.name}</span>
                                            <Badge variant="secondary" className={rule.enabled ? '' : 'opacity-60'}>
                                                {rule.enabled ? 'On' : 'Off'}
                                            </Badge>
                                        </div>
                                        <p className="mt-1 font-mono text-xs text-muted-foreground">
                                            {rule.metric} {rule.operator} {rule.threshold}
                                            {rule.risk_level_min ? ` · min ${rule.risk_level_min}` : ''}
                                            {rule.basin_code ? ` · ${rule.basin_code}` : ' · all basins'}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button asChild variant="outline" size="sm">
                                            <Link
                                                href={`/x/early-warning/alert-rules/${rule.id}`}
                                                method="put"
                                                data={{ enabled: !rule.enabled }}
                                                as="button"
                                                preserveScroll
                                            >
                                                {rule.enabled ? 'Disable' : 'Enable'}
                                            </Link>
                                        </Button>
                                        <Button asChild variant="ghost" size="sm">
                                            <Link
                                                href={`/x/early-warning/alert-rules/${rule.id}`}
                                                method="delete"
                                                as="button"
                                                preserveScroll
                                            >
                                                Delete
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <div className="border-b border-border px-3 py-2">
                        <h2 className="text-sm font-semibold">Add rule</h2>
                    </div>
                    <form onSubmit={submit} className="space-y-3 p-3">
                        <Field label="Name" error={form.errors.name}>
                            <TextInput
                                value={form.data.name}
                                required
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                        <Field label="Basin (optional)">
                            <Select
                                value={form.data.basin_code}
                                onChange={(e) => form.setData('basin_code', e.target.value)}
                            >
                                <option value="">All subscribed basins</option>
                                {basins.map((b) => (
                                    <option key={b.basin_code} value={b.basin_code}>
                                        {b.name} ({b.basin_code})
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Metric" error={form.errors.metric}>
                                <Select
                                    value={form.data.metric}
                                    onChange={(e) => form.setData('metric', e.target.value)}
                                >
                                    {metrics.map((m) => (
                                        <option key={m} value={m}>
                                            {m}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Operator" error={form.errors.operator}>
                                <Select
                                    value={form.data.operator}
                                    onChange={(e) => form.setData('operator', e.target.value)}
                                >
                                    {operators.map((op) => (
                                        <option key={op} value={op}>
                                            {op}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Threshold" error={form.errors.threshold}>
                                <TextInput
                                    type="number"
                                    step="0.1"
                                    min={0}
                                    value={String(form.data.threshold)}
                                    onChange={(e) => form.setData('threshold', Number(e.target.value))}
                                />
                            </Field>
                            <Field label="Min risk level">
                                <Select
                                    value={form.data.risk_level_min}
                                    onChange={(e) => form.setData('risk_level_min', e.target.value)}
                                >
                                    <option value="">None</option>
                                    {risk_levels.map((level) => (
                                        <option key={level} value={level}>
                                            {level}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            Create rule
                        </PrimaryButton>
                    </form>
                </Panel>
            </div>

            <Panel className="mb-8">
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">Delivery channels</h2>
                </div>
                <form onSubmit={saveChannels} className="space-y-3 p-3 max-w-xl">
                    <p className="text-sm text-muted-foreground">
                        Rules with channel <code className="text-xs">email</code> or{' '}
                        <code className="text-xs">telegram</code> use these destinations. Default rules use{' '}
                        <code className="text-xs">log</code> only until you add channels on a rule.
                    </p>
                    <Field label="Notify emails (comma-separated)" error={channelsForm.errors.notify_emails}>
                        <TextInput
                            value={channelsForm.data.notify_emails}
                            placeholder="ops@example.com, duty@example.com"
                            onChange={(e) => channelsForm.setData('notify_emails', e.target.value)}
                        />
                    </Field>
                    <Field label="Telegram chat IDs" error={channelsForm.errors.telegram_chat_ids}>
                        <TextInput
                            value={channelsForm.data.telegram_chat_ids}
                            placeholder="-1001234567890"
                            onChange={(e) => channelsForm.setData('telegram_chat_ids', e.target.value)}
                        />
                    </Field>
                    <Field label="SMS phones" error={channelsForm.errors.notify_phones}>
                        <TextInput
                            value={channelsForm.data.notify_phones}
                            placeholder="+2189…"
                            onChange={(e) => channelsForm.setData('notify_phones', e.target.value)}
                        />
                    </Field>
                    <p className="text-xs text-muted-foreground">
                        Bot token: {channel_settings.telegram_configured ? 'configured' : 'set EW_TELEGRAM_BOT_TOKEN'}
                        {' · '}
                        SMS webhook:{' '}
                        {channel_settings.sms_webhook_configured
                            ? 'configured'
                            : 'log-only (set EW_SMS_WEBHOOK_URL)'}
                    </p>
                    <PrimaryButton type="submit" disabled={channelsForm.processing}>
                        Save channels
                    </PrimaryButton>
                </form>
            </Panel>

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">Recent alert log</h2>
                </div>
                {recent_logs.length === 0 ? (
                    <Empty>No alerts logged yet. Rules evaluate hourly after risk compute.</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {recent_logs.map((log) => (
                            <li key={log.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                                <span className="font-medium">{log.rule_name ?? 'Rule'}</span>
                                <span className="text-muted-foreground">{log.basin_code}</span>
                                <Badge variant="secondary">{log.status}</Badge>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {log.value != null && log.threshold != null
                                        ? `${log.value} / ${log.threshold}`
                                        : log.channel}
                                </span>
                                <span className="ms-auto text-xs text-muted-foreground">
                                    {log.created_at ? when(log.created_at) : '—'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}
