import { whenTime } from '@/lib/format';
import { Link } from '@inertiajs/react';
import { enumLabel } from '@/lib/enum-labels';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, PageHeader, Panel } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import AdvisoryBanner from './AdvisoryBanner';

type ActivationRow = {
    id: number;
    source: string;
    source_alert_id: string;
    hazard_type: string;
    severity?: string | null;
    urgency?: string | null;
    status: string;
    valid_from?: string | null;
    valid_to?: string | null;
    is_exercise: boolean;
    show_url: string;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    activations: ActivationRow[];
};

export default function Activations({ advisory, authority = null, activations }: Props) {
    const { t } = useTranslation();

    return (
        <AppLayout
            title={t('disaster.activations_short')}
            breadcrumbs={[
                { title: t('disaster.title'), href: '/x/disaster' },
                { title: t('disaster.activations_short') },
            ]}
        >
            <PageHeader title={t('disaster.activations')} />
            <AdvisoryBanner
                advisory={advisory}
                authority={authority}
                className="mb-6 max-w-2xl text-sm text-muted-foreground"
            />

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.activations_list')}</h2>
                </div>
                {activations.length === 0 ? (
                    <Empty>{t('disaster.empty.activations')}</Empty>
                ) : (
                    <ul className="divide-y divide-border text-sm">
                        {activations.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <Link href={row.show_url} className="font-medium text-primary hover:underline">
                                    {enumLabel(row.hazard_type)}
                                </Link>
                                <Badge variant="secondary">{enumLabel(row.status)}</Badge>
                                {row.severity ? <Badge variant="outline">{enumLabel(row.severity)}</Badge> : null}
                                {row.is_exercise ? <Badge variant="outline">{t('disaster.exercise')}</Badge> : null}
                                <span className="text-muted-foreground">
                                    {enumLabel(row.source)} #<bdi>{row.source_alert_id}</bdi>
                                </span>
                                <span className="ms-auto text-xs text-muted-foreground">
                                    {row.valid_from ? whenTime(row.valid_from) : t('common.em_dash')}
                                    {' → '}
                                    {row.valid_to ? whenTime(row.valid_to) : t('common.em_dash')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}
