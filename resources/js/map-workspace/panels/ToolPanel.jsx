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

import { __ } from '@/lib/i18n';
const TOOL_GROUPS = [
    {
        id: 'navigate',
        label: 'tools.navigate',
        tools: [
            { id: 'pan', name: 'tools.pan', icon: Hand },
            { id: 'select', name: 'tools.select', icon: MousePointer2 },
            { id: 'identify', name: 'tools.identify', icon: Info },
        ],
    },
    {
        id: 'draw',
        label: 'tools.draw',
        tools: [
            { id: 'draw-point', name: 'tools.draw_point', icon: MapPin },
            { id: 'draw-line', name: 'tools.draw_line', icon: Minus },
            { id: 'draw-polygon', name: 'tools.draw_polygon', icon: Pentagon },
        ],
    },
    {
        id: 'measure',
        label: 'tools.measure',
        tools: [
            { id: 'measure-distance', name: 'tools.measure_distance', icon: Ruler },
            { id: 'measure-area', name: 'tools.measure_area', icon: Square },
        ],
    },
    {
        id: 'view',
        label: 'tools.view',
        tools: [
            { id: 'zoom-in', name: 'tools.zoom_in', icon: ZoomIn },
            { id: 'zoom-out', name: 'tools.zoom_out', icon: ZoomOut },
            { id: 'zoom-extent', name: 'tools.zoom_extent', icon: Maximize2 },
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

function drawToolAllowed(toolId, geometryType) {
    if (!geometryType) {
        return true;
    }

    const geometry = String(geometryType).toLowerCase();
    if (toolId === 'draw-point') {
        return geometry.includes('point');
    }
    if (toolId === 'draw-line') {
        return geometry.includes('line');
    }
    if (toolId === 'draw-polygon') {
        return geometry.includes('polygon') || geometry === 'multipolygon';
    }

    return true;
}

// Tools that change features; hidden for read-only (SQL-view) layers.
const EDIT_TOOLS = new Set(['select', 'draw-point', 'draw-line', 'draw-polygon']);

export default function ToolPanel({ map, onToolSelected, mode = 'edit', geometryType = null, readOnly = false }) {
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
        tools: group.tools.filter((tool) => {
            if (mode === 'view') {
                return VIEW_MODE_TOOLS.has(tool.id);
            }
            if (readOnly && EDIT_TOOLS.has(tool.id)) {
                return false;
            }
            if (mode === 'layer' && tool.id.startsWith('draw-')) {
                return drawToolAllowed(tool.id, geometryType);
            }
            return true;
        }),
    })).filter((group) => group.tools.length > 0);

    return (
        <div className="tool-panel" role="toolbar" aria-label={__('maps.tools')}>
            {groups.map((group) => {
                const open = openGroups[group.id] !== false;
                return (
                    <div key={group.id} className="tool-group" role="group" aria-label={__(group.label)}>
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
                            <span>{__(group.label)}</span>
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
                                        aria-label={__(tool.name)}
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
