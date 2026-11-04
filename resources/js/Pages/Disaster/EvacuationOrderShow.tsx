import { Link, useForm } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Field, PageHeader, Panel, PrimaryButton, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdvisoryBanner from './AdvisoryBanner';
import ZonePreviewMap from './ZonePreviewMap';
import { whenTime } from '@/lib/format';

type OrderEvent = {
    id: number;
    from_status?: string | null;
    to_status: string;
    action: string;
    user_id?: number | null;
    created_at?: string | null;
};

type OrderDetail = {
    id: number;
    status: string;
    allowed_transitions: string[];
    authored_by?: number | null;
    approved_by?: number | null;
    issued_at?: string | null;
    expires_at?: string | null;
    is_official: boolean;
    text_en: string;
    text_ar?: string | null;
    estimated_population?: number | null;
    evacuated_count: number;
    unaccounted_count: number;
    corridor_ids: number[];
    shelter_site_ids: number[];
    layer_feature_ids: number[];
    zone?: { type: string; coordinates: unknown } | null;
    events?: OrderEvent[];
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    order: OrderDetail;
    index_url: string;
    transition_url: string;
    execution_url: string;
    can_manage: boolean;
    can_dispatch: boolean;
    can_field: boolean;
    can_approve_evacuation: boolean;
};

export default function EvacuationOrderShow({
    advisory,
    authority = null,
    order,
    index_url,
    transition_url,
    execution_url,
    can_manage,
    can_dispatch,
    can_field,
    can_approve_evacuation,
}: Props) {
    const { t, locale } = useTranslation();
    const transitionForm = useForm({ status: '', note: '' });
    const executionForm = useForm({
        evacuated_count: order.evacuated_count,
        unaccounted_count: order.unaccounted_count,
        estimated_population: order.estimated_population ?? '',
    });

    function canTransitionTo(status: string): boolean {
        if (!order.allowed_transitions.includes(status)) {
            return false;
        }
        if (status === 'approved') {
            return can_approve_evacuation;
        }
        if (status === 'issued' || status === 'pending_approval' || status === 'draft' || status === 'cancelled') {
            return can_manage || can_dispatch;
        }
        if (status === 'in_progress' || status === 'completed') {
            return can_manage || can_field;
        }
        return can_manage;
    }

    function runTransition(status: string) {
        transitionForm.setData('status', status);
        transitionForm.transform(() => ({ status, note: transitionForm.data.note || null }));
        transitionForm.post(transition_url, { preserveScroll: true });
    }

    function submitExecution(event: React.FormEvent) {
        event.preventDefault();
        executionForm.transform((data) => ({
            ...data,
            estimated_population:
                data.estimated_population === '' ? null : Number(data.estimated_population),
        }));
        executionForm.post(execution_url, { preserveScroll: true });
    }

    const canUpdateExecution =
        (can_manage || can_field) &&
        ['issued', 'in_progress', 'completed'].includes(order.status);

    return (
        <AppLayout
            title={`${t('disaster.evac_orders_short')} #${order.id}`}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.evac_orders_short'), href: index_url },
                { title: `#${order.id}` },
            ]}
        >
            <PageHeader
                title={`${t('disaster.evac_order')} #${order.id}`}
                action={
                    <Link href={index_url} className="text-sm font-medium text-primary hover:underline">
                        {t('disaster.evac_orders_short')}
                    </Link>
                }
            />
            {advisory ? (
                <AdvisoryBanner
                    advisory={advisory}
                    authority={authority}
                    className="mb-6 max-w-2xl text-sm text-muted-foreground"
                />
            ) : null}

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <div className="space-y-3 p-3 text-sm">
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="secondary">{enumLabel(order.status)}</Badge>
                            {order.is_official ? <Badge variant="outline">{t('disaster.official')}</Badge> : null}
                        </div>
                        {/* Arabic UI: the Arabic text is the order; English shows only when there is no Arabic text. */}
                        {locale === 'ar' ? (
                            <Row label={t('disaster.order_text')} value={order.text_ar || order.text_en || t('common.em_dash')} />
                        ) : (
                            <>
                                <Row label={t('disaster.order_text_en')} value={order.text_en || t('common.em_dash')} />
                                <Row label={t('disaster.order_text_ar')} value={order.text_ar || t('common.em_dash')} />
                            </>
                        )}
                        <Row
                            label={t('disaster.estimated_population')}
                            value={String(order.estimated_population ?? t('common.em_dash'))}
                        />
                        <Row label={t('disaster.issued_at')} value={order.issued_at ? whenTime(order.issued_at) : t('common.em_dash')} />
                        <Row label={t('disaster.expires_at')} value={order.expires_at ? whenTime(order.expires_at) : t('common.em_dash')} />
                        <Row label={t('disaster.evacuated_count')} value={String(order.evacuated_count)} />
                        <Row label={t('disaster.unaccounted_count')} value={String(order.unaccounted_count)} />
                        <Row
                            label={t('disaster.corridors')}
                            value={order.corridor_ids.length ? order.corridor_ids.join(', ') : t('common.em_dash')}
                        />
                        <Row
                            label={t('disaster.shelters')}
                            value={
                                order.shelter_site_ids.length
                                    ? order.shelter_site_ids.join(', ')
                                    : t('common.em_dash')
                            }
                        />
                        <div>
                            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                {t('disaster.evac_zone')}
                            </div>
                            <div className="mt-1">
                                <ZonePreviewMap zone={order.zone} />
                            </div>
                        </div>
                    </div>
                </Panel>

                <div className="space-y-6">
                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.transitions')}</h2>
                        </div>
                        <div className="flex flex-wrap gap-2 p-3">
                            {order.allowed_transitions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">{t('disaster.no_transitions')}</p>
                            ) : (
                                order.allowed_transitions.map((status) =>
                                    canTransitionTo(status) ? (
                                        <Button
                                            key={status}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={transitionForm.processing}
                                            onClick={() => runTransition(status)}
                                        >
                                            {enumLabel(status, 'disaster.evac_transition')}
                                        </Button>
                                    ) : null,
                                )
                            )}
                        </div>
                    </Panel>

                    {canUpdateExecution ? (
                        <Panel>
                            <div className="border-b border-border px-3 py-2">
                                <h2 className="text-sm font-semibold">{t('disaster.execution_tracking')}</h2>
                            </div>
                            <form onSubmit={submitExecution} className="space-y-3 p-3">
                                <Field label={t('disaster.evacuated_count')} error={executionForm.errors.evacuated_count}>
                                    <TextInput
                                        type="number"
                                        min={0}
                                        value={String(executionForm.data.evacuated_count)}
                                        onChange={(e) =>
                                            executionForm.setData('evacuated_count', Number(e.target.value))
                                        }
                                    />
                                </Field>
                                <Field
                                    label={t('disaster.unaccounted_count')}
                                    error={executionForm.errors.unaccounted_count}
                                >
                                    <TextInput
                                        type="number"
                                        min={0}
                                        value={String(executionForm.data.unaccounted_count)}
                                        onChange={(e) =>
                                            executionForm.setData('unaccounted_count', Number(e.target.value))
                                        }
                                    />
                                </Field>
                                <PrimaryButton type="submit" disabled={executionForm.processing}>
                                    {t('disaster.update_execution')}
                                </PrimaryButton>
                            </form>
                        </Panel>
                    ) : null}

                    <Panel>
                        <div className="border-b border-border px-3 py-2">
                            <h2 className="text-sm font-semibold">{t('disaster.audit_trail')}</h2>
                        </div>
                        <ul className="divide-y divide-border text-sm">
                            {(order.events ?? []).map((event) => (
                                <li key={event.id} className="px-3 py-2">
                                    <div className="font-medium">{enumLabel(event.action, 'disaster.evac_action')}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {event.from_status ? enumLabel(event.from_status) : t('common.em_dash')}{' '}
                                        <span className="inline-block rtl:rotate-180">→</span> {enumLabel(event.to_status)}
                                        {event.created_at ? ` · ${whenTime(event.created_at)}` : ''}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
            <div className="mt-1 whitespace-pre-wrap text-foreground">{value}</div>
        </div>
    );
}
