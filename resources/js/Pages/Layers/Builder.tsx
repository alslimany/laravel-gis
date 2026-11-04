import { useLayoutEffect } from 'react';
import { Head } from '@inertiajs/react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useTranslation } from '@/lib/i18n';
import MapWorkspace from '../../map-workspace/MapWorkspace';
import { useMapStore } from '../../map-workspace/store/mapStore';
import 'bootstrap/dist/css/bootstrap.min.css';
import '../../map-workspace/map-workspace.css';

type WorkspacePayload = {
    id?: number | null;
    name?: string | null;
    layers?: unknown[];
    workspaceMode?: string;
    layerId?: number;
};

export default function Builder({ initialWorkspace }: { initialWorkspace: WorkspacePayload }) {
    const { t } = useTranslation();

    useLayoutEffect(() => {
        useMapStore.getState().hydrateFromInitialData(initialWorkspace);
    }, [initialWorkspace]);

    return (
        <TooltipProvider delayDuration={0}>
            <Head title={initialWorkspace?.name || t('layers.builder')} />
            <MapWorkspace
                key={`layer-${initialWorkspace?.layerId ?? 'new'}`}
                mode="layer"
            />
        </TooltipProvider>
    );
}
