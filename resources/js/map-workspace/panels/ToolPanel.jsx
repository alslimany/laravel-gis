import { useEffect, useState } from 'react';
import {
    ChevronDown,
    Hand,
    MousePointer2,
    MapPin,
    Minus,
    Pentagon,
    Ruler,
    Square,
    Info,
    ZoomIn,
    ZoomOut,
    Maximize2,
} from 'lucide-react';
import { IconButton } from '../components/IconButton';
import { useMapStore } from '../store/mapStore';

const TOOL_GROUPS = [
    {
        id: 'navigate',
        label: 'Navigate',
        tools: [
            { id: 'pan', name: 'Pan', icon: Hand },
            { id: 'select', name: 'Select', icon: MousePointer2 },
            { id: 'identify', name: 'Identify', icon: Info },
        ],
    },
    {
        id: 'draw',
        label: 'Draw',
        tools: [
            { id: 'draw-point', name: 'Draw point', icon: MapPin },
            { id: 'draw-line', name: 'Draw line', icon: Minus },
            { id: 'draw-polygon', name: 'Draw polygon', icon: Pentagon },
        ],
    },
    {
        id: 'measure',
        label: 'Measure',
        tools: [
            { id: 'measure-distance', name: 'Measure distance', icon: Ruler },
            { id: 'measure-area', name: 'Measure area', icon: Square },
        ],
    },
    {
        id: 'view',
        label: 'View',
        tools: [
            { id: 'zoom-in', name: 'Zoom in', icon: ZoomIn },
            { id: 'zoom-out', name: 'Zoom out', icon: ZoomOut },
            { id: 'zoom-extent', name: 'Zoom to extent', icon: Maximize2 },
        ],
    },
];

const ONE_SHOT_TOOLS = ['zoom-in', 'zoom-out', 'zoom-extent'];
const TOGGLE_TOOLS = [
    'pan',
    'select',
    'draw-point',
    'draw-line',
    'draw-polygon',
    'measure-distance',
    'measure-area',
    'identify',
];

const VIEW_MODE_TOOLS = new Set(['pan', 'identify', 'zoom-in', 'zoom-out', 'zoom-extent']);

export default function ToolPanel({ map, onToolSelected, mode = 'edit' }) {
    const storeMap = useMapStore((state) => state.map);
    const storeActiveTool = useMapStore((state) => state.activeTool);
    const setActiveTool = useMapStore((state) => state.setActiveTool);
    const [activeTool, setLocalActiveTool] = useState(storeActiveTool);
    const [openGroups, setOpenGroups] = useState(() =>
        Object.fromEntries(TOOL_GROUPS.map((group) => [group.id, true]))
    );

    useEffect(() => {
        setLocalActiveTool(storeActiveTool);
    }, [storeActiveTool]);

    const executeZoomTool = (toolId) => {
        const mapInstance = map || storeMap;

        if (!mapInstance) {
            console.warn('Map instance not available');
            return;
        }

        switch (toolId) {
            case 'zoom-in': {
                const view = mapInstance.getView();
                view.animate({
                    zoom: view.getZoom() + 1,
                    duration: 250,
                });
                break;
            }
            case 'zoom-out': {
                const viewOut = mapInstance.getView();
                viewOut.animate({
                    zoom: viewOut.getZoom() - 1,
                    duration: 250,
                });
                break;
            }
            case 'zoom-extent': {
                const extent = mapInstance.getView().calculateExtent(mapInstance.getSize());
                mapInstance.getView().fit(extent, {
                    duration: 250,
                    padding: [50, 50, 50, 50],
                });
                break;
            }
            default:
                break;
        }
    };

    const selectTool = (toolId) => {
        if (ONE_SHOT_TOOLS.includes(toolId)) {
            executeZoomTool(toolId);
            onToolSelected?.(activeTool ?? null);
            return;
        }

        if (TOGGLE_TOOLS.includes(toolId)) {
            const next = activeTool === toolId ? null : toolId;
            setLocalActiveTool(next);
            setActiveTool(next);
            onToolSelected?.(next);
            return;
        }

        setLocalActiveTool(toolId);
        setActiveTool(toolId);
        onToolSelected?.(toolId);
    };

    const groups = TOOL_GROUPS.map((group) => ({
        ...group,
        tools:
            mode === 'view'
                ? group.tools.filter((tool) => VIEW_MODE_TOOLS.has(tool.id))
                : group.tools,
    })).filter((group) => group.tools.length > 0);

    return (
        <div className="tool-panel" role="toolbar" aria-label="Map tools">
            {groups.map((group) => {
                const open = openGroups[group.id] !== false;
                return (
                    <div key={group.id} className="tool-group" role="group" aria-label={group.label}>
                        <button
                            type="button"
                            className="tool-group-title"
                            aria-expanded={open}
                            onClick={() =>
                                setOpenGroups((current) => ({
                                    ...current,
                                    [group.id]: !open,
                                }))
                            }
                        >
                            <span>{group.label}</span>
                            <ChevronDown
                                className={`tool-group-chevron${open ? ' is-open' : ''}`}
                                strokeWidth={1.75}
                                aria-hidden="true"
                            />
                        </button>
                        {open ? (
                            <div className="tool-group-tools">
                                {group.tools.map((tool) => (
                                    <IconButton
                                        key={tool.id}
                                        variant={activeTool === tool.id ? 'solid' : 'ghost'}
                                        aria-label={tool.name}
                                        aria-pressed={activeTool === tool.id}
                                        icon={<tool.icon className="size-4" strokeWidth={1.75} />}
                                        onClick={() => selectTool(tool.id)}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}
