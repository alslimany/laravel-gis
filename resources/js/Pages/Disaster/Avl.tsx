import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { Empty, PageHeader, Panel } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { when } from '@/lib/format';
import AdvisoryBanner from './AdvisoryBanner';
import { disasterTermPrefix, termLabel } from '@/lib/disaster-terms';

type Unit = {
    id: number;
    unit_id: string;
    resource_class: string;
    resource_status: string;
    last_ping_at?: string | null;
    speed_kmh?: number | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    units: Unit[];
};

export default function Avl({ advisory, authority = null, units }: Props) {
    const { t } = useTranslation();
    return (
        <AppLayout
            title={t('disaster.avl_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.avl_short') }]}
        >
            <PageHeader title={t('disaster.avl_page')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            <Panel>
                {units.length === 0 ? (
                    <Empty>{t('disaster.empty.avl_units')}</Empty>
                ) : (
                    <ul className="divide-y divide-border text-sm">
                        {units.map((unit) => (
                            <li key={unit.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <span className="font-medium">{unit.unit_id}</span>
                                <Badge variant="secondary">
                                    {termLabel(t, disasterTermPrefix.resourceStatus, unit.resource_status)}
                                </Badge>
                                <span className="text-xs text-muted-foreground">{unit.resource_class}</span>
                                <span className="ms-auto text-xs text-muted-foreground">
                                    {unit.last_ping_at ? when(unit.last_ping_at) : t('disaster.no_ping')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}
