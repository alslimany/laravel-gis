import { Heading } from '@ninna-ui/primitives';
import { useState } from 'react';
import PublicLayout from '../../Layouts/PublicLayout';
import WidgetBoard from '../../dashboards/WidgetBoard';

export default function Public({ dashboard, widgetData = [], dataUrl }) {
    const [filters, setFilters] = useState({});

    return (
        <PublicLayout title={dashboard.name}>
            <Heading as="h1">{dashboard.name}</Heading>
            {dashboard.description ? <p className="mb-4 mt-2 text-muted">{dashboard.description}</p> : null}
            <WidgetBoard
                widgets={dashboard.widgets || []}
                widgetData={widgetData}
                dataUrl={dataUrl || `/dashboards/shared/${dashboard.share_token}/data`}
                filters={filters}
                onFiltersChange={setFilters}
            />
        </PublicLayout>
    );
}
