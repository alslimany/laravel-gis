import { createRoot } from 'react-dom/client';
import '../bootstrap';
import '../../css/app.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useMapStore } from './store/mapStore';
import MapWorkspace from './MapWorkspace';
import './map-workspace.css';

const mountNode = document.getElementById('map-builder-app');

if (mountNode) {
    if (window.initialMapData) {
        useMapStore.getState().hydrateFromInitialData(window.initialMapData);
    }

    // Avoid StrictMode double-mount so OpenLayers is created once.
    createRoot(mountNode).render(
        <TooltipProvider delayDuration={0}>
            <MapWorkspace />
        </TooltipProvider>,
    );
}
