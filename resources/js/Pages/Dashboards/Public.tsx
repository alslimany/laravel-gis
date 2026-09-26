import { useState } from 'react';
import PublicLayout from '@/layouts/public-layout';
import WidgetBoard from '@/dashboards/WidgetBoard';
import { filtersFromSearch } from '@/dashboards/filters';
import { Heading, Pill } from '@/components/gis';
import { when } from '@/lib/format';

function visibleWidgets(widgets = []) {
    return widgets.filter((widget) => widget.type !== 'map' || widget.layer_id || widget.map_id);
}

export default function Public({ dashboard, widgetData = [], dataUrl, organizationName = null }) {
    const [filters, setFilters] = useState(() => filtersFromSearch(typeof window === 'undefined' ? '' : window.location.search));
    const widgets = visibleWidgets(dashboard.widgets || []);

    return (
        <PublicLayout title={dashboard.name}>
            <article className="mx-auto flex max-w-6xl flex-col gap-6">
                <header className="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">{organizationName || 'Shared dashboard'}</p>
                        <Heading as="h1" className="mt-1 text-2xl">
                            {dashboard.name}
                        </Heading>
                        {dashboard.description ? <p className="mt-2 max-w-2xl text-sm text-muted-foreground">{dashboard.description}</p> : null}
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Pill live>Shared</Pill>
                        <p className="text-sm text-muted-foreground">Updated {when(dashboard.updated_at)}</p>
                    </div>
                </header>
                <WidgetBoard
                    widgets={widgets}
                    widgetData={widgetData}
                    dataUrl={dataUrl || `/dashboards/shared/${dashboard.share_token}/data`}
                    filters={filters}
                    onFiltersChange={setFilters}
                />
                <footer className="border-t border-border pt-4 text-sm text-muted-foreground">Shared monitoring view</footer>
            </article>
        </PublicLayout>
    );
}
