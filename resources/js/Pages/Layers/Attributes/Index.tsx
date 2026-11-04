import { router } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import { useEffect, useRef, useState } from 'react';
import Map from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import { fromLonLat } from 'ol/proj';
import { localizedControls } from '@/lib/ol-controls';
import { Style, Stroke, Fill, Circle as CircleStyle } from 'ol/style';
import type Feature from 'ol/Feature';
import 'ol/ol.css';
import AppLayout from '@/layouts/app-layout';
import { count } from '@/lib/format';
import { DangerButton, Empty, GhostLink, PageHeader, Pager, Panel, PrimaryButton, Select, Table, TextInput, DataTable, thClass, tdClass, tdMono } from '@/components/gis';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type FeatureRow = Record<string, string | number | null>;

type ShapeCount = { type: string; count: number };

const idleStyle = new Style({
    stroke: new Stroke({ color: '#334155', width: 2 }),
    fill: new Fill({ color: 'rgba(71, 85, 105, 0.35)' }),
    image: new CircleStyle({
        radius: 6,
        fill: new Fill({ color: '#334155' }),
        stroke: new Stroke({ color: '#ffffff', width: 1 }),
    }),
});

const selectedStyle = new Style({
    stroke: new Stroke({ color: '#0f172a', width: 3 }),
    fill: new Fill({ color: 'rgba(15, 23, 42, 0.55)' }),
    image: new CircleStyle({
        radius: 8,
        fill: new Fill({ color: '#0f172a' }),
        stroke: new Stroke({ color: '#ffffff', width: 2 }),
    }),
});

type SelectedShape = {
    id: string;
    type: string;
    fields: Array<[string, string]>;
};

function featureId(feature: Feature): string {
    const id = feature.getId() ?? feature.get('id') ?? feature.get('gid');
    return id == null ? '' : String(id);
}

function featureFields(feature: Feature): Array<[string, string]> {
    return Object.entries(feature.getProperties())
        .filter(([key, value]) => key !== 'geometry' && value != null && value !== '' && typeof value !== 'object')
        .map(([key, value]) => [key, String(value)] as [string, string])
        .filter(([, value]) => value.length < 80)
        .slice(0, 6);
}

function LayerMap({ layerId, canEdit }: { layerId: number; canEdit: boolean }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<Map | null>(null);
    const sourceRef = useRef<VectorSource | null>(null);
    const layerRef = useRef<VectorLayer | null>(null);
    const filterRef = useRef<string | null>(null);
    const selectedRef = useRef<Feature | null>(null);
    const [status, setStatus] = useState<'loading' | 'ready' | 'empty' | 'error'>('loading');
    const [filter, setFilter] = useState<string | null>(null);
    const [types, setTypes] = useState<string[]>([]);
    const [selected, setSelected] = useState<SelectedShape | null>(null);
    const [removing, setRemoving] = useState(false);

    useEffect(() => {
        const target = containerRef.current;
        if (!target) {
            return undefined;
        }

        let map: Map | null = null;
        let cancelled = false;
        const source = new VectorSource();
        const vector = new VectorLayer({
            source,
            style: (feature) => {
                const type = feature.getGeometry()?.getType() || '';
                if (filterRef.current && type !== filterRef.current) {
                    return undefined;
                }
                return feature === selectedRef.current ? selectedStyle : idleStyle;
            },
        });
        sourceRef.current = source;
        layerRef.current = vector;

        map = new Map({
            target,
            layers: [new TileLayer({ source: new OSM() }), vector],
            view: new View({ center: fromLonLat([0, 0]), zoom: 2 }),
            controls: localizedControls({ attribution: false, zoom: true }),
        });
        mapRef.current = map;

        map.on('pointermove', (event) => {
            const hit = map?.hasFeatureAtPixel(event.pixel, { hitTolerance: 6 });
            map.getTargetElement().style.cursor = hit ? 'pointer' : '';
        });

        map.on('singleclick', (event) => {
            const hit = map?.forEachFeatureAtPixel(event.pixel, (feature) => {
                const item = feature as Feature;
                const type = item.getGeometry()?.getType() || '';
                if (filterRef.current && type !== filterRef.current) {
                    return undefined;
                }
                return item;
            }, { hitTolerance: 6 });
            selectedRef.current = hit && featureId(hit) ? hit : null;
            vector.changed();
            if (!selectedRef.current) {
                setSelected(null);
                return;
            }
            setSelected({
                id: featureId(selectedRef.current),
                type: selectedRef.current.getGeometry()?.getType() || __('attr.shape'),
                fields: featureFields(selectedRef.current),
            });
        });

        fetch(`/layers/${layerId}/geojson`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Could not load the layer.');
                }
                return response.json();
            })
            .then((geojson) => {
                if (cancelled || !map) {
                    return;
                }
                const features = new GeoJSON().readFeatures(geojson, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857',
                });
                source.addFeatures(features);
                setTypes([...new Set(features.map((feature) => feature.getGeometry()?.getType() || '').filter(Boolean))]);
                const fit = () => {
                    map?.updateSize();
                    const extent = source.getExtent();
                    if (features.length && extent.every((value) => Number.isFinite(value))) {
                        map?.getView().fit(extent, { padding: [40, 40, 40, 40], maxZoom: 16 });
                    }
                };
                setStatus(features.length ? 'ready' : 'empty');
                fit();
                window.setTimeout(fit, 200);
            })
            .catch(() => {
                if (!cancelled) {
                    setStatus('error');
                }
            });

        return () => {
            cancelled = true;
            map?.setTarget(undefined);
            mapRef.current = null;
            sourceRef.current = null;
            layerRef.current = null;
        };
    }, [layerId]);

    function focusFilter(next: string | null) {
        filterRef.current = next;
        setFilter(next);
        selectedRef.current = null;
        setSelected(null);
        layerRef.current?.changed();
        const visible = (sourceRef.current?.getFeatures() || []).filter((feature) => !next || feature.getGeometry()?.getType() === next);
        if (!visible.length || !mapRef.current) {
            return;
        }
        const extent = new VectorSource({ features: visible }).getExtent();
        if (extent.every((value) => Number.isFinite(value))) {
            mapRef.current.getView().fit(extent, { padding: [48, 48, 48, 48], maxZoom: 16, duration: 200 });
        }
    }

    function removeSelected() {
        if (!selected || removing) {
            return;
        }
        setRemoving(true);
        router.delete(`/layers/${layerId}/features/${selected.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const feature = sourceRef.current?.getFeatures().find((item) => featureId(item) === selected.id);
                if (feature) {
                    sourceRef.current?.removeFeature(feature);
                }
                selectedRef.current = null;
                setSelected(null);
                layerRef.current?.changed();
            },
            onFinish: () => setRemoving(false),
        });
    }

    return (
        <div className="relative overflow-hidden rounded-md border">
            <div ref={containerRef} className="h-[min(70vh,36rem)] w-full" />
            <div className="absolute top-3 start-3 z-10 flex max-w-[calc(100%-1.5rem)] flex-wrap gap-1">
                <Button type="button" size="sm" variant={filter ? 'outline' : 'default'} onClick={() => focusFilter(null)}>
                    {__('attr.all_shapes')}
                </Button>
                {types.map((type) => (
                    <Button key={type} type="button" size="sm" variant={filter === type ? 'default' : 'outline'} onClick={() => focusFilter(type)}>
                        {type}
                    </Button>
                ))}
            </div>
            {status === 'loading' ? <p className="absolute inset-x-0 top-14 text-center text-sm text-muted-foreground">{__('attr.loading')}</p> : null}
            {status === 'empty' ? <p className="absolute inset-x-0 top-14 text-center text-sm text-muted-foreground">{__('attr.no_geometry')}</p> : null}
            {status === 'error' ? <p className="absolute inset-x-0 top-14 text-center text-sm text-destructive">{__('attr.load_failed')}</p> : null}
            {selected ? (
                <div className="absolute end-3 bottom-3 start-3 z-10 rounded-lg border bg-background p-3 shadow-md sm:start-auto sm:w-72">
                    <p className="text-sm font-medium">{selected.type}</p>
                    <dl className="mt-2 grid gap-1">
                        {selected.fields.map(([name, value]) => (
                            <div key={name} className="flex justify-between gap-3 text-xs">
                                <dt className="text-muted-foreground">{name}</dt>
                                <dd className="truncate font-medium">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    {canEdit ? (
                        <Button type="button" variant="destructive" size="sm" className="mt-3" disabled={removing} onClick={removeSelected}>
                            {removing ? __('attr.removing') : __('attr.remove_shape')}
                        </Button>
                    ) : null}
                </div>
            ) : status === 'ready' ? (
                <p className="pointer-events-none absolute bottom-3 start-3 z-10 rounded-md bg-background/90 px-2 py-1 text-xs text-muted-foreground">
                    {__('attr.click_hint')}
                </p>
            ) : null}
        </div>
    );
}

export default function Index({
    layer,
    features,
    columns = [],
    columnLabels = {},
    columnDomains = {},
    shapes = [],
    filters = {},
    canEdit = false,
    readOnly = false,
}: {
    layer: { id: number; name: string };
    features?: { data?: FeatureRow[] };
    columns?: string[];
    columnLabels?: Record<string, string>;
    columnDomains?: Record<string, string[] | null>;
    shapes?: ShapeCount[];
    filters?: { search?: string | null; shape?: string | null };
    canEdit?: boolean;
    readOnly?: boolean;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search || '');
    const [mapOpen, setMapOpen] = useState(false);
    const [selectedIds, setSelectedIds] = useState<Array<number | string>>([]);
    const [bulkAction, setBulkAction] = useState('');
    const [editingId, setEditingId] = useState<number | string | null>(null);
    const [draft, setDraft] = useState<Record<string, string>>({});
    const rows = features?.data || [];
    const pageIds = rows.map((feature) => feature.id).filter((id) => id != null) as Array<number | string>;
    const allPageSelected = pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));
    const editable = columns.filter((column) => column !== 'id' && column !== 'shape');

    function load(next: { search?: string; shape?: string | null }) {
        router.get(
            `/layers/${layer.id}/attributes`,
            {
                search: next.search ?? search,
                shape: next.shape === undefined ? filters.shape : next.shape,
            },
            { preserveState: true },
        );
    }

    function submit(event: { preventDefault: () => void }) {
        event.preventDefault();
        load({ search });
    }

    function startEdit(feature: FeatureRow) {
        const next: Record<string, string> = {};
        editable.forEach((column) => {
            next[column] = feature[column] == null ? '' : String(feature[column]);
        });
        setEditingId(feature.id);
        setDraft(next);
    }

    function saveEdit(featureId: number | string) {
        const original = rows.find((feature) => feature.id === featureId);
        if (!original) {
            return;
        }
        // Send only edited columns so untouched legacy values are never re-validated.
        const changed: Record<string, string> = {};
        Object.entries(draft).forEach(([column, value]) => {
            const before = original[column] == null ? '' : String(original[column]);
            if (value !== before) {
                changed[column] = value;
            }
        });
        if (Object.keys(changed).length === 0) {
            setEditingId(null);
            return;
        }
        router.put(`/layers/${layer.id}/features/${featureId}`, { attributes: changed }, { preserveScroll: true, onSuccess: () => setEditingId(null) });
    }

    function removeFeature(featureId: number | string) {
        if (!window.confirm(__('attr.confirm_remove'))) {
            return;
        }
        router.delete(`/layers/${layer.id}/features/${featureId}`, { preserveScroll: true });
    }

    function toggleSelected(featureId: number | string, checked: boolean) {
        setSelectedIds((current) => (checked ? [...current, featureId] : current.filter((id) => id !== featureId)));
    }

    function togglePage(checked: boolean) {
        setSelectedIds(checked ? pageIds : selectedIds.filter((id) => !pageIds.includes(id)));
    }

    function applyBulk(event: { preventDefault: () => void }) {
        event.preventDefault();
        if (bulkAction !== 'remove' || selectedIds.length === 0) {
            return;
        }
        if (!window.confirm(__(selectedIds.length === 1 ? 'attr.confirm_bulk_one' : 'attr.confirm_bulk_many', { count: selectedIds.length }))) {
            return;
        }
        router.post(`/layers/${layer.id}/features/bulk-destroy`, { ids: selectedIds }, {
            preserveScroll: true,
            onSuccess: () => setSelectedIds([]),
        });
    }

    return (
        <AppLayout title={__('attr.title', { name: layer.name })}>
            <PageHeader
                title={layer.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" onClick={() => setMapOpen(true)}>
                            {__('attr.show_on_map')}
                        </Button>
                        <GhostLink href={`/layers/${layer.id}`}>{t('layers.back_layer')}</GhostLink>
                    </div>
                }
            />
            <Dialog open={mapOpen} onOpenChange={setMapOpen}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{t('layers.layer_shape')}</DialogTitle>
                        <DialogDescription>{layer.name}</DialogDescription>
                    </DialogHeader>
                    {mapOpen ? <LayerMap layerId={layer.id} canEdit={canEdit} /> : null}
                </DialogContent>
            </Dialog>
            <form onSubmit={submit} className="mb-4 flex max-w-xl gap-2">
                <TextInput value={search} placeholder={t('layers.search_attributes')} onChange={(event) => setSearch(event.target.value)} />
                <PrimaryButton type="submit">{t('actions.search')}</PrimaryButton>
            </form>
            {readOnly ? <p className="mb-4 text-sm text-muted-foreground">{t('layers.read_only_notice')}</p> : null}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <GhostLink href={`/layers/${layer.id}/attributes`}>{t('layers.all_shapes')}</GhostLink>
                {shapes.map((shape) => (
                    <button
                        key={shape.type}
                        type="button"
                        className="text-sm text-primary hover:underline"
                        onClick={() => load({ shape: shape.type })}
                    >
                        {shape.type} ({count(shape.count)})
                    </button>
                ))}
            </div>
            {canEdit && rows.length > 0 ? (
                <form onSubmit={applyBulk} className="flex max-w-md items-center gap-2">
                    <Select value={bulkAction} onChange={(event) => setBulkAction(event.target.value)} aria-label={t('layers.bulk_actions')}>
                        <option value="">{t('layers.bulk_actions')}</option>
                        <option value="remove">{t('actions.remove')}</option>
                    </Select>
                    <PrimaryButton type="submit" disabled={bulkAction !== 'remove' || selectedIds.length === 0}>
                        {__('attr.apply')}{selectedIds.length ? ` (${selectedIds.length})` : ''}
                    </PrimaryButton>
                </form>
            ) : null}
            <Panel>
                {rows.length === 0 ? (
                    <Empty>{__('attr.no_match')}</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                {canEdit ? (
                                    <Table.Head className={thClass}>
                                        <Checkbox
                                            checked={allPageSelected}
                                            onCheckedChange={(checked) => togglePage(checked === true)}
                                            aria-label={__('attr.select_all')}
                                        />
                                    </Table.Head>
                                ) : null}
                                {columns.map((column) => (
                                    <Table.Head key={column} className={thClass}>
                                        {columnLabels[column] || column}
                                    </Table.Head>
                                ))}
                                {canEdit ? <Table.Head className={thClass}>{t('forms.actions')}</Table.Head> : null}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((feature, index) => {
                                const editing = canEdit && editingId === feature.id;
                                return (
                                    <Table.Row key={feature.id || index}>
                                        {canEdit ? (
                                            <Table.Cell className={tdClass}>
                                                <Checkbox
                                                    checked={feature.id != null && selectedIds.includes(feature.id)}
                                                    onCheckedChange={(checked) => feature.id != null && toggleSelected(feature.id, checked === true)}
                                                    aria-label={__('attr.select_feature', { id: String(feature.id ?? index + 1) })}
                                                />
                                            </Table.Cell>
                                        ) : null}
                                        {columns.map((column) => {
                                            const domain = columnDomains[column];
                                            const current = draft[column] ?? '';
                                            const options =
                                                domain && current !== '' && !domain.includes(current)
                                                    ? [current, ...domain]
                                                    : domain;
                                            return (
                                                <Table.Cell key={column} className={`${tdMono} max-w-xs`}>
                                                    {editing && editable.includes(column) ? (
                                                        options && options.length > 0 ? (
                                                            <Select
                                                                value={current}
                                                                aria-label={column}
                                                                onChange={(event) =>
                                                                    setDraft((state) => ({
                                                                        ...state,
                                                                        [column]: event.target.value,
                                                                    }))
                                                                }
                                                            >
                                                                <option value="">{t('common.em_dash')}</option>
                                                                {options.map((option) => (
                                                                    <option key={option} value={option}>
                                                                        {option}
                                                                    </option>
                                                                ))}
                                                            </Select>
                                                        ) : (
                                                            <TextInput
                                                                value={current}
                                                                onChange={(event) =>
                                                                    setDraft((state) => ({
                                                                        ...state,
                                                                        [column]: event.target.value,
                                                                    }))
                                                                }
                                                            />
                                                        )
                                                    ) : (
                                                        <span className="block truncate">
                                                            {feature[column] == null || feature[column] === ''
                                                                ? t('common.em_dash')
                                                                : String(feature[column])}
                                                        </span>
                                                    )}
                                                </Table.Cell>
                                            );
                                        })}
                                        {canEdit ? (
                                            <Table.Cell className={tdClass}>
                                                {editing ? (
                                                    <span className="flex gap-2">
                                                        <PrimaryButton type="button" onClick={() => saveEdit(feature.id as number)}>
                                                            {__('attr.save')}
                                                        </PrimaryButton>
                                                        <GhostLink href={`/layers/${layer.id}/attributes`}>{t('actions.cancel')}</GhostLink>
                                                    </span>
                                                ) : (
                                                    <span className="flex gap-2">
                                                        <button type="button" className="text-sm text-primary hover:underline" onClick={() => startEdit(feature)}>
                                                            {__('attr.edit')}
                                                        </button>
                                                        <DangerButton type="button" onClick={() => removeFeature(feature.id as number)}>
                                                            {__('attr.remove')}
                                                        </DangerButton>
                                                    </span>
                                                )}
                                            </Table.Cell>
                                        ) : null}
                                    </Table.Row>
                                );
                            })}
                        </Table.Body>
                    </DataTable>
                )}
                <Pager paginator={features} />
            </Panel>
        </AppLayout>
    );
}
