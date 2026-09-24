import { Head } from '@inertiajs/react';
import Builder from '@/dashboards/Builder';

export default function Editor({ dashboard = null, layers = [], catalog = [], previewUrl }) {
    return (
        <>
            <Head title={dashboard ? `Edit ${dashboard.name}` : 'New dashboard'} />
            <Builder dashboard={dashboard} layers={layers} catalog={catalog} previewUrl={previewUrl} />
        </>
    );
}
