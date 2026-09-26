import { Head } from '@inertiajs/react';
import Builder from '@/dashboards/Builder';

export default function Editor({ dashboard = null, layers = [], maps = [], catalog = [], previewUrl }) {
    return (
        <>
            <Head title={dashboard ? `Edit ${dashboard.name}` : 'New dashboard'} />
            <Builder dashboard={dashboard} layers={layers} maps={maps} catalog={catalog} previewUrl={previewUrl} />
        </>
    );
}
