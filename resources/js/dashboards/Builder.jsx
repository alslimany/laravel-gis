import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import Library from './Library';
import Inspector from './Inspector';
import WidgetBoard from './WidgetBoard';
import { applyGridLayout, createWidget, normalizeWidgets } from './widgetDocument';
import { PrimaryButton, TextInput } from '@/components/gis';

function csrfToken() {
    return document.head.querySelector('meta[name="csrf-token"]')?.content || '';
}

function seedBinding(widget, layers) {
    if (widget.type === 'map') {
        widget.source = widget.source || 'layer';
        widget.map_id = widget.map_id ?? null;
        return widget;
    }
    if (widget.type !== 'text') {
        const preferred = layers.find((layer) => layer.published) || layers[0];
        if (preferred) widget.layer_id = preferred.id;
    }
    return widget;
}

export default function Builder({ dashboard = null, layers = [], maps = [], catalog = [], previewUrl }) {
    const editing = Boolean(dashboard);
    const [widgets, setWidgets] = useState(() => normalizeWidgets(dashboard?.widgets || []));
    const [selectedId, setSelectedId] = useState(null);
    const [previewData, setPreviewData] = useState([]);
    const [previewError, setPreviewError] = useState(null);
    const [name, setName] = useState(dashboard?.name || 'Untitled dashboard');
    const [description, setDescription] = useState(dashboard?.description || '');
    const [isPublic, setIsPublic] = useState(Boolean(dashboard?.is_public));
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const [draggingType, setDraggingType] = useState(null);

    const selected = useMemo(() => widgets.find((widget) => widget.id === selectedId) || null, [widgets, selectedId]);

    const refreshPreview = useCallback(
        async (nextWidgets) => {
            if (!previewUrl) return;
            try {
                const response = await fetch(previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ widgets: nextWidgets, filters: {} }),
                });
                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload.message || 'Preview failed');
                }
                setPreviewData(payload.widgets || []);
                setPreviewError(null);
            } catch (error) {
                setPreviewError(error.message);
            }
        },
        [previewUrl],
    );

    useEffect(() => {
        const timer = window.setTimeout(() => refreshPreview(widgets), 350);
        return () => window.clearTimeout(timer);
    }, [widgets, refreshPreview]);

    function updateWidgetsFrom(updater) {
        setWidgets((current) => updater(current));
    }

    function addWidget(type, layout = null) {
        const widget = seedBinding(createWidget(type, widgets.length), layers);
        if (layout) {
            widget.layout = {
                x: layout.x ?? 0,
                y: layout.y ?? 0,
                w: layout.w ?? widget.layout.w,
                h: layout.h ?? widget.layout.h,
            };
        }
        updateWidgetsFrom((current) => [...current, widget]);
        setSelectedId(widget.id);
    }

    function dropWidget(type, layout, nextLayout) {
        const widget = seedBinding(createWidget(type, Date.now()), layers);
        if (layout) {
            widget.layout = {
                x: layout.x ?? 0,
                y: layout.y ?? 0,
                w: layout.w ?? widget.layout.w,
                h: layout.h ?? widget.layout.h,
            };
        }
        updateWidgetsFrom((current) => {
            const positioned = nextLayout?.length
                ? applyGridLayout(
                      current,
                      nextLayout.filter((item) => item.i !== '__dropping__'),
                  )
                : current;
            return [...positioned, widget];
        });
        setSelectedId(widget.id);
        setDraggingType(null);
    }

    function patchWidget(nextWidget) {
        updateWidgetsFrom((current) => current.map((widget) => (widget.id === nextWidget.id ? nextWidget : widget)));
    }

    function removeWidget(id) {
        updateWidgetsFrom((current) => {
            const next = current.filter((widget) => widget.id !== id);
            setSelectedId((currentId) => (currentId === id ? next[0]?.id || null : currentId));
            return next;
        });
    }

    function submit(event) {
        event.preventDefault();
        const payload = {
            name,
            description,
            is_public: isPublic ? 1 : 0,
            widgets,
        };
        setProcessing(true);
        setErrors({});
        const visit = {
            onError: (nextErrors) => setErrors(nextErrors || {}),
            onFinish: () => setProcessing(false),
        };
        if (editing) {
            router.put(`/dashboards/${dashboard.id}`, payload, visit);
        } else {
            router.post('/dashboards', payload, visit);
        }
    }

    return (
        <div className="flex h-screen flex-col bg-background text-foreground">
            <header className="shrink-0 border-b border-border bg-muted/40">
                <div className="flex items-center gap-3 px-4 py-3">
                        <Link href={editing ? `/dashboards/${dashboard.id}` : '/dashboards'} className="text-primary hover:underline">
                            Back
                        </Link>
                        <div className="grid min-w-0 flex-1 gap-2 md:grid-cols-2">
                            <TextInput value={name} onChange={(event) => setName(event.target.value)} aria-label="Dashboard name" />
                            <TextInput
                                value={description}
                                onChange={(event) => setDescription(event.target.value)}
                                placeholder="Description for the shared view"
                                aria-label="Dashboard description"
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={isPublic} onChange={(event) => setIsPublic(event.target.checked)} />
                            Share publicly
                        </label>
                        <PrimaryButton type="button" disabled={processing} onClick={submit}>
                            {editing ? 'Save dashboard' : 'Create dashboard'}
                        </PrimaryButton>
                </div>
            </header>

            {(errors.name || errors.widgets || previewError) && (
                <div className="border-b border-destructive/40 bg-destructive/10 px-4 py-2 text-destructive">
                    {errors.name || errors.widgets || previewError}
                </div>
            )}

            <div className="flex min-h-0 flex-1">
                <Library
                    catalog={catalog}
                    onAdd={addWidget}
                    onDragType={setDraggingType}
                />
                <main className="min-w-0 flex-1 overflow-auto p-4">
                    <WidgetBoard
                        widgets={widgets}
                        widgetData={previewData}
                        editable
                        selectedId={selectedId}
                        draggingType={draggingType}
                        onSelect={setSelectedId}
                        onRemove={removeWidget}
                        onDropWidget={dropWidget}
                        onLayoutChange={(layout) => updateWidgetsFrom((current) => applyGridLayout(current, layout))}
                    />
                </main>
                <Inspector widget={selected} layers={layers} maps={maps} onChange={patchWidget} onClose={() => setSelectedId(null)} />
            </div>
        </div>
    );
}
