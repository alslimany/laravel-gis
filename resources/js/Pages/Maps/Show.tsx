import { useLayoutEffect } from 'react';
import { Head } from '@inertiajs/react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useTranslation } from '@/lib/i18n';
import MapWorkspace from '../../map-workspace/MapWorkspace';
import { useMapStore } from '../../map-workspace/store/mapStore';
import 'bootstrap/dist/css/bootstrap.min.css';
import '../../map-workspace/map-workspace.css';

export default function Show({ initialMap }) {
    const { t } = useTranslation();

    useLayoutEffect(() => {
        useMapStore.getState().hydrateFromInitialData(initialMap);
    }, [initialMap]);

    return (
        <TooltipProvider delayDuration={0}>
            <Head title={initialMap?.name || t('maps.map')} />
            <MapWorkspace mode="view" key={initialMap?.id ?? 'map'} />
        </TooltipProvider>
    );
}
