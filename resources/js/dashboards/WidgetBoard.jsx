import { useEffect, useMemo, useState } from 'react';
import GridLayout, { useContainerWidth, verticalCompactor } from 'react-grid-layout';
import { GripVertical, Trash2 } from 'lucide-react';
import { WidgetBody, WidgetFrame, mergeWidgetData } from './widgets';
import { DEFAULT_SIZE, canonicalType } from './widgetDocument';
import { writeFiltersToSearch } from './filters';
import 'react-grid-layout/css/styles.css';

function toLayout(widgets, editable) {
    return widgets.map((widget) => ({
        i: widget.id,
        x: widget.layout?.x ?? 0,
        y: widget.layout?.y ?? 0,
        w: widget.layout?.w ?? 4,
        h: widget.layout?.h ?? 3,
        minW: 2,
        minH: 2,
        static: !editable,
    }));
}

function readDropType(event) {
    return (
        event?.dataTransfer?.getData('application/x-dashboard-widget') ||
        event?.dataTransfer?.getData('text/plain') ||
        ''
    );
}

export default function WidgetBoard({
    widgets = [],
    widgetData = [],
    dataUrl = null,
    editable = false,
    selectedId = null,
    onSelect = null,
    onLayoutChange = null,
    onRemove = null,
    onDropWidget = null,
    draggingType = null,
    filters: controlledFilters = null,
    onFiltersChange = null,
}) {
    const { width, containerRef, mounted } = useContainerWidth({ measureBeforeMount: true });
    const [filters, setFilters] = useState(controlledFilters || {});
    const [liveData, setLiveData] = useState(widgetData);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState(null);

    useEffect(() => {
        if (controlledFilters) setFilters(controlledFilters);
    }, [controlledFilters]);

    useEffect(() => {
        setLiveData(widgetData);
    }, [widgetData]);

    const merged = useMemo(() => mergeWidgetData(widgets, liveData), [widgets, liveData]);
    const layout = useMemo(() => toLayout(merged, editable), [merged, editable]);
    const dropSize = DEFAULT_SIZE[canonicalType(draggingType)] || { w: 4, h: 3 };

    useEffect(() => {
        if (!dataUrl || editable) return undefined;
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([layerId, value]) => {
            if (value) params.append(`filters[${layerId}]`, value);
        });
        const url = params.toString() ? `${dataUrl}?${params}` : dataUrl;
        let cancelled = false;
        setLoading(true);
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response) => {
                if (!response.ok) throw new Error('refresh failed');
                return response.json();
            })
            .then((payload) => {
                if (!cancelled && payload.widgets) {
                    setLiveData(payload.widgets);
                    setLoadError(null);
                }
            })
            .catch(() => {
                if (!cancelled) setLoadError('The dashboard could not refresh. The last loaded numbers are still on screen.');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [dataUrl, editable, filters]);

    function setFilter(layerId, value) {
        const next = { ...filters };
        if (!value) delete next[layerId];
        else next[layerId] = value;
        setFilters(next);
        onFiltersChange?.(next);
        if (!editable) writeFiltersToSearch(next);
    }

    function handleDrop(nextLayout, item, event) {
        const type = readDropType(event) || draggingType;
        if (!type || !item) return;
        onDropWidget?.(type, {
            x: item.x,
            y: item.y,
            w: item.w,
            h: item.h,
        }, nextLayout);
    }

    return (
        <div
            ref={containerRef}
            className={`relative min-h-[320px] ${loading ?'opacity-80' :''}`}
            onDragOver={(event) => {
                if (!editable) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            }}
        >
            {loadError ? (
                <p className="mb-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{loadError}</p>
            ) : null}
            {!editable && Object.keys(filters).length ? (
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <span className="text-sm text-muted-foreground">Filtered</span>
                    {Object.entries(filters).map(([layerId, value]) => (
                        <button
                            key={layerId}
                            type="button"
                            className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-sm text-foreground"
                            onClick={() => setFilter(layerId, null)}
                        >
                            {value}
                            <span className="text-muted-foreground">Clear</span>
                        </button>
                    ))}
                </div>
            ) : null}
            {!merged.length && editable ? (
                <div className="pointer-events-none absolute inset-0 z-0 flex items-center justify-center rounded-lg border border-dashed border-border bg-card px-6 text-center">
                    <p className="max-w-sm text-sm text-muted-foreground">Drag a component here, or click one in the library.</p>
                </div>
            ) : null}
            {!merged.length && !editable ? (
                <div className="flex min-h-[240px] items-center justify-center rounded-lg border border-dashed border-border bg-card px-6 text-center">
                    <p className="max-w-sm text-sm text-muted-foreground">This dashboard has no widgets yet.</p>
                </div>
            ) : null}
            {mounted ? (
                <GridLayout
                    className="layout relative z-10"
                    width={width}
                    layout={layout}
                    gridConfig={{ cols: 12, rowHeight: 56, margin: [12, 12], containerPadding: [0, 0] }}
                    dragConfig={{ enabled: editable, handle: '.widget-drag-handle' }}
                    resizeConfig={{ enabled: editable }}
                    dropConfig={{
                        enabled: editable,
                        defaultItem: { w: dropSize.w, h: dropSize.h },
                        onDragOver: () => ({ w: dropSize.w, h: dropSize.h }),
                    }}
                    droppingItem={{
                        i: '__dropping__',
                        w: dropSize.w,
                        h: dropSize.h,
                    }}
                    compactor={verticalCompactor}
                    onLayoutChange={(next) => {
                        if (next.some((item) => item.i === '__dropping__')) return;
                        onLayoutChange?.(next);
                    }}
                    onDrop={handleDrop}
                    onDropDragOver={() => ({ w: dropSize.w, h: dropSize.h })}
                >
                    {merged.map((widget) => (
                        <div key={widget.id} className="h-full">
                            <WidgetFrame
                                title={widget.title}
                                selected={editable && selectedId === widget.id}
                                onClick={() => onSelect?.(widget.id)}
                                actions={
                                    editable ? (
                                        <div className="flex items-center gap-1">
                                            <span
                                                className="widget-drag-handle inline-flex h-9 w-9 cursor-grab items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground active:cursor-grabbing"
                                                aria-label="Move widget"
                                                title="Move"
                                            >
                                                <GripVertical className="h-4 w-4" strokeWidth={1.75} />
                                            </span>
                                            <button
                                                type="button"
                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md text-destructive hover:bg-muted"
                                                aria-label="Remove widget"
                                                title="Remove"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    onRemove?.(widget.id);
                                                }}
                                            >
                                                <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                        </div>
                                    ) : null
                                }
                            >
                                <WidgetBody
                                    widget={widget}
                                    filters={filters}
                                    onFilterChange={setFilter}
                                    interactiveFilters={!editable}
                                />
                            </WidgetFrame>
                        </div>
                    ))}
                </GridLayout>
            ) : (
                <div className="h-40" />
            )}
        </div>
    );
}
