import { Head } from '@inertiajs/react';
import Builder from '@/dashboards/Builder';
import { useTranslation } from '@/lib/i18n';

export default function Editor({ dashboard = null, layers = [], maps = [], analyses = [], catalog = [], previewUrl }) {
    const { t } = useTranslation();
    const pageTitle = dashboard ? t('dashboards.edit_name', { name: dashboard.name }) : t('dashboards.new');

    return (
        <>
            <Head title={pageTitle} />
            <Builder
                dashboard={dashboard}
                layers={layers}
                maps={maps}
                analyses={analyses}
                catalog={catalog}
                previewUrl={previewUrl}
            />
        </>
    );
}
