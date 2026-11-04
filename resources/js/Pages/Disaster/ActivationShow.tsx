import { whenTime } from '@/lib/format';
import { Link, useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, PageHeader, Panel, PrimaryButton } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import AdvisoryBanner from './AdvisoryBanner';

type ActivationDetail = {
    id: number;
    source: string;
    source_alert_id: string;
    hazard_type: string;
    severity?: string | null;
    urgency?: string | null;
    certainty?: string | null;
    status: string;
    valid_from?: string | null;
    valid_to?: string | null;
    affected: unknown[];
    advisory_text?: string | null;
    is_exercise: boolean;
    created_at?: string | null;
};

type TimelineEvent = {
    kind: string;
    at?: string | null;
    who?: string | null;
    user_id?: number | null;
    role?: string | null;
    channel?: string | null;
    status?: string | null;
    response?: string | null;
    acknowledged_at?: string | null;
    event_type?: string | null;
    payload?: Record<string, unknown>;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    activation: ActivationDetail;
    timeline: TimelineEvent[];
    index_url: string;
    acknowledge_url: string;
    escalation_rules_url: string;
    can_dispatch: boolean;
    can_manage: boolean;
};

export default function ActivationShow({
    advisory,
    authority = null,
    activation,
    timeline,
    index_url,
    acknowledge_url,
    escalation_rules_url,
    can_dispatch,
    can_manage,
}: Props) {
    const { t } = useTranslation();
    const ackForm = useForm({ response: 'acknowledged' });

    function acknowledge(response: 'acknowledged' | 'declined') {
        ackForm.transform(() => ({ response })).post(acknowledge_url, { preserveScroll: true });
    }

    return (
        <AppLayout
            title={t('disaster.activation_detail')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.activations_short'), href: index_url },
                { title: `#${activation.id}` },
            ]}
        >
            <PageHeader
                title={`${enumLabel(activation.hazard_type)} #${activation.id}`}
                action={
                    <div className="flex flex-wrap gap-2">
                        {can_manage ? (
                            <Link href={escalation_rules_url} className="text-sm font-medium text-primary hover:underline">
                                {t('disaster.escalation_rules_short')}
                            </Link>
                        ) : null}
                        <Link href={index_url} className="text-sm font-medium text-primary hover:underline">
                            {t('disaster.activations_short')}
                        </Link>
                    </div>
                }
            />
            <AdvisoryBanner
                advisory={advisory}
                authority={authority}
                className="mb-6 max-w-2xl text-sm text-muted-foreground"
            />

            <Panel className="mb-6">
                <div className="space-y-3 p-3 text-sm">
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="secondary">{enumLabel(activation.status)}</Badge>
                        {activation.severity ? <Badge variant="outline">{enumLabel(activation.severity)}</Badge> : null}
                        {activation.urgency ? <Badge variant="outline">{enumLabel(activation.urgency)}</Badge> : null}
                        {activation.certainty ? <Badge variant="outline">{enumLabel(activation.certainty)}</Badge> : null}
                        {activation.is_exercise ? <Badge variant="outline">{t('disaster.exercise')}</Badge> : null}
                    </div>
                    <Row label={t('disaster.source_alert')} value={`${enumLabel(activation.source)} #${activation.source_alert_id}`} />
                    <Row label={t('disaster.valid_from')} value={activation.valid_from ? whenTime(activation.valid_from) : t('common.em_dash')} />
                    <Row label={t('disaster.valid_to')} value={activation.valid_to ? whenTime(activation.valid_to) : t('common.em_dash')} />
                    <Row label={t('disaster.created_at')} value={activation.created_at ? whenTime(activation.created_at) : t('common.em_dash')} />
                    <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            {t('disaster.advisory_text')}
                        </div>
                        <p className="mt-1 whitespace-pre-wrap text-foreground">
                            {activation.advisory_text || t('common.em_dash')}
                        </p>
                    </div>
                    {can_dispatch ? (
                        <div className="flex flex-wrap gap-2 pt-2">
                            <PrimaryButton
                                type="button"
                                disabled={ackForm.processing}
                                onClick={() => acknowledge('acknowledged')}
                            >
                                {t('disaster.acknowledge')}
                            </PrimaryButton>
                            <PrimaryButton
                                type="button"
                                disabled={ackForm.processing}
                                onClick={() => acknowledge('declined')}
                            >
                                {t('disaster.decline')}
                            </PrimaryButton>
                        </div>
                    ) : null}
                </div>
            </Panel>

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.warning_timeline')}</h2>
                </div>
                {timeline.length === 0 ? (
                    <Empty>{t('disaster.empty.timeline')}</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {timeline.map((event, index) => (
                            <li key={`${event.kind}-${event.at}-${index}`} className="space-y-1 px-3 py-2 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">{enumLabel(event.status ?? event.event_type ?? event.kind)}</Badge>
                                    {event.channel ? <Badge variant="outline">{enumLabel(event.channel)}</Badge> : null}
                                    {event.role ? <Badge variant="outline">{enumLabel(event.role)}</Badge> : null}
                                </div>
                                <div className="text-foreground">
                                    {event.who
                                        ? t('disaster.timeline_who', { who: event.who })
                                        : event.event_type
                                          ? t('disaster.timeline_event', { event: enumLabel(event.event_type) })
                                          : t('common.em_dash')}
                                </div>
                                <div className="text-xs text-muted-foreground">{event.at ? whenTime(event.at) : t('common.em_dash')}</div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
            <div className="mt-1 text-foreground">{value}</div>
        </div>
    );
}
