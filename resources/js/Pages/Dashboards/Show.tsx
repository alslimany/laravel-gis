import { useState } from 'react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import WidgetBoard from '@/dashboards/WidgetBoard';
import { filtersFromSearch } from '@/dashboards/filters';
import { DangerButton, GhostLink, PageHeader, Pill, destroyResource } from '@/components/gis';

export default function Show({ dashboard, widgetData = [] }) {
    const { t } = useTranslation();
    const [filters, setFilters] = useState(() => filtersFromSearch(typeof window === 'undefined' ? '' : window.location.search));

    return (
        <AppLayout title={dashboard.name}>
            <PageHeader
                title={dashboard.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        {dashboard.is_public && dashboard.share_token ? (
                            <GhostLink href={`/dashboards/shared/${dashboard.share_token}`}>{t('dash.public_link')}</GhostLink>
                        ) : (
                            <Pill>{t('common.private')}</Pill>
                        )}
                        <a href={`/dashboards/${dashboard.id}/data`} className="inline-flex items-center rounded-md border border-border bg-card px-4">
                            JSON
                        </a>
                        <GhostLink href={`/dashboards/${dashboard.id}/edit`}>{t('actions.edit')}</GhostLink>
                        <DangerButton onClick={() => destroyResource(`/dashboards/${dashboard.id}`, t('dash.confirm_delete'))}>{t('actions.delete')}</DangerButton>
                    </div>
                }
            />
            {dashboard.description ? <p className="mb-4 text-muted-foreground">{dashboard.description}</p> : null}
            <WidgetBoard
                widgets={dashboard.widgets || []}
                widgetData={widgetData}
                dataUrl={`/dashboards/${dashboard.id}/data`}
                filters={filters}
                onFiltersChange={setFilters}
            />
        </AppLayout>
    );
}
