import { useEffect, useState } from 'react';
import { enumLabel } from '@/lib/enum-labels';
import { Direction } from 'radix-ui';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Eye, EyeOff, Layers, Plus, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useMapStore } from '../store/mapStore';

import { __ } from '@/lib/i18n';
function SortableLayerItem({
    layer,
    selectedLayerId,
    onSelect,
    onToggleVisibility,
    onRemove,
    readOnly = false,
    lockComposition = false,
}) {
    const { attributes, listeners, setNodeRef, transform, transition } = useSortable({
        id: layer.id,
        disabled: readOnly || lockComposition,
    });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    const canReorder = !readOnly && !lockComposition;

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={`layer-item${layer.id === selectedLayerId ? ' active' : ''}`}
            onClick={() => onSelect(layer.id)}
            {...(canReorder ? attributes : {})}
            {...(canReorder ? listeners : {})}
        >
            <div className="layer-info">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6"
                    onPointerDown={(event) => event.stopPropagation()}
                    onClick={(event) => {
                        event.stopPropagation();
                        onToggleVisibility(layer.id);
                    }}
                    aria-label={layer.visible ? __('maps.hide_layer') : __('maps.show_layer')}
                >
                    {layer.visible ? (
                        <Eye className="size-4" strokeWidth={1.75} aria-hidden="true" />
                    ) : (
                        <EyeOff className="size-4" strokeWidth={1.75} aria-hidden="true" />
                    )}
                </Button>
                <span className="layer-name">{layer.name}</span>
            </div>
            {readOnly || lockComposition ? null : (
            <div className="layer-actions">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6 text-destructive hover:text-destructive"
                    onPointerDown={(event) => event.stopPropagation()}
                    onClick={(event) => {
                        event.stopPropagation();
                        onRemove(layer.id);
                    }}
                    aria-label={__('maps.remove_layer')}
                >
                    <Trash2 className="size-4" strokeWidth={1.75} aria-hidden="true" />
                </Button>
            </div>
            )}
        </div>
    );
}

const emptyLayerForm = {
    type: 'wms',
    name: '',
    url: '',
    layers: '',
};

export default function LayerPanel({ onClose, onLayerSelected, readOnly = false, lockComposition = false }) {
    const isRtlDir = Direction.useDirection() === 'rtl';
    const layers = useMapStore((state) => state.layers);
    const selectedLayerId = useMapStore((state) => state.selectedLayer);
    const availableBasemaps = useMapStore((state) => state.availableBasemaps);
    const basemap = useMapStore((state) => state.basemap);
    const setBasemap = useMapStore((state) => state.setBasemap);
    const selectLayer = useMapStore((state) => state.selectLayer);
    const toggleLayerVisibility = useMapStore((state) => state.toggleLayerVisibility);
    const removeLayer = useMapStore((state) => state.removeLayer);
    const addLayer = useMapStore((state) => state.addLayer);
    const updateLayerOrder = useMapStore((state) => state.updateLayerOrder);

    const [showModal, setShowModal] = useState(false);
    const [layerSource, setLayerSource] = useState('custom');
    const [selectedGeoServerLayer, setSelectedGeoServerLayer] = useState('');
    const [publishedLayers, setPublishedLayers] = useState([]);
    const [newLayer, setNewLayer] = useState({ ...emptyLayerForm });
    const [selectedBasemap, setSelectedBasemap] = useState(basemap);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    const loadPublishedLayers = async () => {
        try {
            const response = await window.axios.get('/layers', {
                headers: { Accept: 'application/json' },
                params: { published: 1 },
            });

            const payload = response.data?.data || response.data || [];
            const list = (Array.isArray(payload) ? payload : []).filter((layer) => layer.published);
            setPublishedLayers(list);
        } catch (error) {
            console.error('Error loading published layers:', error);
        }
    };

    useEffect(() => {
        loadPublishedLayers();
    }, []);

    useEffect(() => {
        setSelectedBasemap(basemap);
    }, [basemap]);

    const closeModal = () => {
        setShowModal(false);
        setLayerSource('custom');
        setSelectedGeoServerLayer('');
        setNewLayer({ ...emptyLayerForm });
    };

    const handleAddLayer = () => {
        if (!newLayer.name) {
            alert('Please fill in all required fields');
            return;
        }

        if (newLayer.type === 'wms' && newLayer.layerId) {
            addLayer({
                id: newLayer.layerId,
                name: newLayer.name,
                type: 'wms',
                url: newLayer.url,
                layers: newLayer.layers,
                wmsParams: newLayer.wmsParams || { SORTING: 'acquired D' },
                visible: true,
                opacity: 1,
            });
            closeModal();
            return;
        }

        if (newLayer.type === 'mvt' && newLayer.layerId) {
            addLayer({
                id: newLayer.layerId,
                name: newLayer.name,
                type: 'mvt',
                mvtUrl: `/api/layers/${newLayer.layerId}/tiles/{z}/{x}/{y}.mvt`,
                url: `/api/layers/${newLayer.layerId}/geojson`,
                visible: true,
                opacity: 1,
                style: newLayer.styleConfig || {},
                style_config: newLayer.styleConfig || {},
                geometry_type: newLayer.geometry_type || null,
                timeField: newLayer.timeField || null,
            });
            closeModal();
            return;
        }

        if (!newLayer.url) {
            alert('Please fill in all required fields');
            return;
        }

        addLayer({
            id: Date.now().toString(),
            name: newLayer.name,
            type: newLayer.type,
            url: newLayer.url,
            layers: newLayer.layers,
            visible: true,
            opacity: 1,
            style: {},
        });
        closeModal();
    };

    const onGeoServerLayerSelect = (layerId) => {
        setSelectedGeoServerLayer(layerId);
        const layer = publishedLayers.find((item) => String(item.id) === String(layerId));
        if (!layer) {
            return;
        }

        const geoserverUrl =
            import.meta.env.VITE_GEOSERVER_URL || 'http://127.0.0.1:8081/geoserver';

        if (layer.geometry_type === 'Raster') {
            const workspace = layer.geoserver_workspace;
            const coverage = layer.geoserver_layer_name || layer.table_name;
            setNewLayer({
                type: 'wms',
                layerId: layer.id,
                name: layer.name,
                url: `${geoserverUrl}/wms`,
                layers: workspace ? `${workspace}:${coverage}` : coverage,
                wmsParams: layer.metadata?.wms_params || { SORTING: 'acquired D' },
            });
            return;
        }

        // Prefer PostGIS MVT; keep WMS metadata as fallback fields.
        setNewLayer({
            type: 'mvt',
            layerId: layer.id,
            name: layer.name,
            url: `${geoserverUrl}/wms`,
            layers:
                layer.geoserver_layer_name ||
                `${layer.geoserver_workspace}:${layer.table_name}`,
                styleConfig: layer.style_config || {},
                geometry_type: layer.geometry_type,
                timeField: layer.metadata?.time_field || null,
        });
    };

    const handleDragEnd = (event) => {
        const { active, over } = event;
        if (!over || active.id === over.id) {
            return;
        }

        const oldIndex = layers.findIndex((layer) => layer.id === active.id);
        const newIndex = layers.findIndex((layer) => layer.id === over.id);
        updateLayerOrder(arrayMove(layers, oldIndex, newIndex));
    };

    const handleRemove = (layerId) => {
        if (confirm('Are you sure you want to remove this layer?')) {
            removeLayer(layerId);
        }
    };

    return (
        <div className="layer-panel">
            <div className="panel-header">
                <h5>{__('maps.layers')}</h5>
                <div className="flex items-center gap-1">
                    {readOnly || lockComposition ? null : (
                    <Button type="button" size="sm" className="h-8 gap-1.5" onClick={() => setShowModal(true)}>
                        <Plus className="size-3.5" strokeWidth={1.75} />
                        {__('maps.add_layer')}
                    </Button>
                    )}
                    {onClose ? (
                        <Button type="button" variant="ghost" size="icon" className="size-8" onClick={onClose} aria-label={__('common.close')}>
                            <X className="size-4" />
                        </Button>
                    ) : null}
                </div>
            </div>

            {readOnly ? null : (
            <div className="basemap-selector">
                <Label className="mb-2">{__('maps.base_map')}</Label>
                <Select
                    value={selectedBasemap}
                    onValueChange={(value) => {
                        setSelectedBasemap(value);
                        setBasemap(value);
                    }}
                >
                    <SelectTrigger className="w-full bg-background" aria-label={__('maps.base_map')}>
                        <SelectValue placeholder={__('maps.base_map')} />
                    </SelectTrigger>
                    <SelectContent>
                        {availableBasemaps.map((item) => (
                            <SelectItem key={item.id} value={item.id}>
                                {__(`maps.basemap.${item.id}`, undefined, item.name)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            )}

            <div className="layers-list">
                {layers.length === 0 ? (
                    <div className="empty-state">
                        <Layers className="glyph lg" strokeWidth={1.5} aria-hidden="true" />
                        <p>{__('maps.no_layers_on_map')}</p>
                        {readOnly || lockComposition ? null : (
                        <a href="/imports/create" className="text-sm font-medium text-primary underline-offset-4 hover:underline">
                            {__('dashboard.import_data')}
                        </a>
                        )}
                    </div>
                ) : (
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={lockComposition ? undefined : handleDragEnd}
                    >
                        <SortableContext
                            items={layers.map((layer) => layer.id)}
                            strategy={verticalListSortingStrategy}
                        >
                            <div className="layer-items">
                                {layers.map((layer) => (
                                    <SortableLayerItem
                                        key={layer.id}
                                        layer={layer}
                                        selectedLayerId={selectedLayerId}
                                        readOnly={readOnly}
                                        lockComposition={lockComposition}
                                        onSelect={(id) => {
                                            selectLayer(id);
                                            onLayerSelected?.(id);
                                        }}
                                        onToggleVisibility={toggleLayerVisibility}
                                        onRemove={handleRemove}
                                    />
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                )}
            </div>

            <Sheet
                modal={false}
                open={showModal}
                onOpenChange={(open) => {
                    if (!open) closeModal();
                }}
            >
                <SheetContent
                    side={isRtlDir ? 'left' : 'right'}
                    showOverlay={false}
                    onInteractOutside={(event) => event.preventDefault()}
                    onPointerDownOutside={(event) => event.preventDefault()}
                    onFocusOutside={(event) => event.preventDefault()}
                    className={`inset-y-auto top-[4.25rem] ${isRtlDir ? 'left-[1.65rem]' : 'right-[1.65rem]'} bottom-auto z-40 h-auto max-h-[calc(100vh-7.5rem)] w-[min(22rem,calc(100vw-7rem))] gap-0 overflow-hidden rounded-xl border p-0 shadow-lg sm:max-w-none`}
                >
                    <SheetHeader className="border-b">
                        <SheetTitle>{__('maps.add_layer')}</SheetTitle>
                        <SheetDescription>{__('maps.add_layer_hint')}</SheetDescription>
                    </SheetHeader>
                    <div className="grid min-h-0 flex-1 gap-4 overflow-y-auto p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="layer-source">{__('maps.layer_source')}</Label>
                            <Select
                                value={layerSource}
                                onValueChange={(value) => {
                                    setLayerSource(value);
                                    if (value === 'geoserver') {
                                        loadPublishedLayers();
                                    }
                                }}
                            >
                                <SelectTrigger id="layer-source" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="z-[90]">
                                    <SelectItem value="custom">{__('maps.custom_url')}</SelectItem>
                                    <SelectItem value="geoserver">{__('maps.from_geoserver')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {layerSource === 'geoserver' ? (
                            <div className="grid gap-2">
                                <Label htmlFor="published-layers">{__('maps.published_layers')}</Label>
                                <Select
                                    value={selectedGeoServerLayer || undefined}
                                    onValueChange={onGeoServerLayerSelect}
                                >
                                    <SelectTrigger id="published-layers" className="w-full">
                                        <SelectValue placeholder={__('maps.select_published')} />
                                    </SelectTrigger>
                                    <SelectContent className="z-[90]">
                                        {publishedLayers.map((layer) => (
                                            <SelectItem key={layer.id} value={String(layer.id)}>
                                                {layer.name} ({enumLabel(layer.geometry_type)})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {publishedLayers.length === 0 ? (
                                    <p className="text-xs text-muted-foreground">
                                        {__('maps.no_published')}{' '}
                                        <a href="/layers" className="font-medium text-primary underline-offset-4 hover:underline">
                                            {__('dashboard.publish_layer')}
                                        </a>
                                        {' '}or{' '}
                                        <a href="/imports/create" className="font-medium text-primary underline-offset-4 hover:underline">
                                            {__('maps.import_dataset_lc')}
                                        </a>
                                        .
                                    </p>
                                ) : (
                                    <p className="text-xs text-muted-foreground">{__('maps.only_published')}</p>
                                )}
                            </div>
                        ) : null}

                        {layerSource === 'custom' ? (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="layer-type">{__('maps.layer_type')}</Label>
                                    <Select
                                        value={newLayer.type}
                                        onValueChange={(value) =>
                                            setNewLayer((prev) => ({ ...prev, type: value }))
                                        }
                                    >
                                        <SelectTrigger id="layer-type" className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="z-[90]">
                                            <SelectItem value="wms">{__('maps.wms_layer')}</SelectItem>
                                            <SelectItem value="wfs">{__('maps.wfs_layer')}</SelectItem>
                                            <SelectItem value="vector">{__('maps.vector_layer')}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="layer-name">{__('maps.layer_name')}</Label>
                                    <Input
                                        id="layer-name"
                                        value={newLayer.name}
                                        onChange={(event) =>
                                            setNewLayer((prev) => ({ ...prev, name: event.target.value }))
                                        }
                                        placeholder={__('maps.enter_layer_name')}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="layer-url">URL</Label>
                                    <Input
                                        id="layer-url"
                                        value={newLayer.url}
                                        onChange={(event) =>
                                            setNewLayer((prev) => ({ ...prev, url: event.target.value }))
                                        }
                                        placeholder={__('maps.enter_layer_url')}
                                    />
                                </div>
                                {newLayer.type === 'wms' ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="layer-names">{__('maps.layer_names')}</Label>
                                        <Input
                                            id="layer-names"
                                            value={newLayer.layers}
                                            onChange={(event) =>
                                                setNewLayer((prev) => ({ ...prev, layers: event.target.value }))
                                            }
                                            placeholder="e.g., workspace:layername"
                                        />
                                    </div>
                                ) : null}
                            </>
                        ) : null}

                        {layerSource === 'geoserver' && selectedGeoServerLayer ? (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="gs-name">{__('maps.layer_name')}</Label>
                                    <Input id="gs-name" value={newLayer.name} readOnly />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="gs-url">{__('maps.geoserver_url')}</Label>
                                    <Input id="gs-url" value={newLayer.url} readOnly />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="gs-layer">{__('maps.layer')}</Label>
                                    <Input id="gs-layer" value={newLayer.layers} readOnly />
                                </div>
                            </>
                        ) : null}
                    </div>
                    <SheetFooter className="flex-row justify-end border-t">
                        <Button type="button" variant="outline" onClick={closeModal}>
                            {__('common.cancel')}
                        </Button>
                        <Button type="button" onClick={handleAddLayer}>
                            {__('maps.add_layer')}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        </div>
    );
}
