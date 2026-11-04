import { Link, useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, Select, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';

type RuleRow = {
    id: number;
    timeout_minutes: number;
    next_recipient_role: string;
    channel: string;
    sort_order: number;
    update_url: string;
    destroy_url: string;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    rules: RuleRow[];
    channels: string[];
    roles: string[];
    store_url: string;
    can_manage: boolean;
};

export default function EscalationRules({
    advisory,
    authority = null,
    rules,
    channels,
    roles,
    store_url,
    can_manage,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        timeout_minutes: 15,
        next_recipient_role: 'admin',
        channel: 'email',
        sort_order: 0,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(store_url, {
            preserveScroll: true,
            onSuccess: () => form.reset('timeout_minutes', 'next_recipient_role', 'channel', 'sort_order'),
        });
    }

    return (
        <AppLayout
            title={t('disaster.escalation_rules_short')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.escalation_rules_short') },
            ]}
        >
            <PageHeader title={t('disaster.escalation_rules')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <Panel className="mb-6 max-w-xl">
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.add_escalation_rule')}</h2>
                </div>
                {can_manage ? (
                    <form onSubmit={submit} className="space-y-3 p-3">
                        <Field label={t('disaster.timeout_minutes')} error={form.errors.timeout_minutes}>
                            <TextInput
                                type="number"
                                min={1}
                                value={form.data.timeout_minutes}
                                onChange={(e) => form.setData('timeout_minutes', Number(e.target.value))}
                            />
                        </Field>
                        <Field label={t('disaster.next_recipient_role')} error={form.errors.next_recipient_role}>
                            <Select
                                value={form.data.next_recipient_role}
                                onChange={(e) => form.setData('next_recipient_role', e.target.value)}
                            >
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('disaster.channel')} error={form.errors.channel}>
                            <Select value={form.data.channel} onChange={(e) => form.setData('channel', e.target.value)}>
                                {channels.map((channel) => (
                                    <option key={channel} value={channel}>
                                        {channel}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('disaster.sort_order')} error={form.errors.sort_order}>
                            <TextInput
                                type="number"
                                min={0}
                                value={form.data.sort_order}
                                onChange={(e) => form.setData('sort_order', Number(e.target.value))}
                            />
                        </Field>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {t('disaster.save_rule')}
                        </PrimaryButton>
                    </form>
                ) : (
                    <p className="p-3 text-sm text-muted-foreground">{t('disaster.manage_required_rules')}</p>
                )}
            </Panel>

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.escalation_rules_list')}</h2>
                </div>
                {rules.length === 0 ? (
                    <Empty>{t('disaster.empty.escalation_rules')}</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {rules.map((rule) => (
                            <li key={rule.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                <span className="font-medium">
                                    {t('disaster.rule_summary', {
                                        minutes: rule.timeout_minutes,
                                        role: rule.next_recipient_role,
                                        channel: rule.channel,
                                    })}
                                </span>
                                {can_manage ? (
                                    <Button asChild variant="ghost" size="sm" className="ms-auto">
                                        <Link href={rule.destroy_url} method="delete" as="button" preserveScroll>
                                            {t('common.delete')}
                                        </Link>
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}
