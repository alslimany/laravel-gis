import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import WidgetBoard from '../../dashboards/WidgetBoard';
import { DangerButton, GhostLink, PageHeader, destroyResource } from '../../Components/ui';

export default function Show({ dashboard, widgetData = [] }) {
    const [filters, setFilters] = useState({});

    return (
        <AppLayout title={dashboard.name}>
            <PageHeader
                title={dashboard.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        {dashboard.is_public && dashboard.share_token ? (
                            <GhostLink href={`/dashboards/shared/${dashboard.share_token}`}>Public link</GhostLink>
                        ) : null}
                        <a href={`/dashboards/${dashboard.id}/data`} className="inline-flex items-center rounded-md border border-line bg-panel px-4">
                            JSON
                        </a>
                        <GhostLink href={`/dashboards/${dashboard.id}/edit`}>Edit</GhostLink>
                        <DangerButton onClick={() => destroyResource(`/dashboards/${dashboard.id}`, 'Delete this dashboard?')}>Delete</DangerButton>
                    </div>
                }
            />
            {dashboard.description ? <p className="mb-4 text-muted">{dashboard.description}</p> : null}
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
