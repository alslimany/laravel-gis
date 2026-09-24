import { useLayoutEffect } from 'react';
import { Head } from '@inertiajs/react';
import { TooltipProvider } from '@/components/ui/tooltip';
import MapWorkspace from '../../map-workspace/MapWorkspace';
import { useMapStore } from '../../map-workspace/store/mapStore';
import 'bootstrap/dist/css/bootstrap.min.css';
import '../../map-workspace/map-workspace.css';

export default function Builder({ initialMap }) {
    useLayoutEffect(() => {
        useMapStore.getState().hydrateFromInitialData(initialMap);
    }, [initialMap]);

    return (
        <TooltipProvider delayDuration={0}>
            <Head title={initialMap?.name || 'Map builder'} />
            <MapWorkspace key={initialMap?.id ?? initialMap?.name ?? 'new'} />
        </TooltipProvider>
    );
}
