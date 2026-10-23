import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type FormEvent, type PointerEvent as ReactPointerEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, GhostLink, PageHeader, Panel, PrimaryButton, TextInput } from '@/components/gis';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type LayerNode = {
    id: number;
    name: string;
    table_name: string;
    geometry_type?: string | null;
    feature_count: number;
    key_stem: string;
    columns: string[];
};

type RelationshipRecord = {
    id: number;
    name: string;
    foreign_key: string;
    layer_id: number;
    related_layer_id: number;
    parent_name?: string | null;
    child_name?: string | null;
};

type Point = { x: number; y: number };

type PlacedNode = { node: LayerNode; x: number; y: number };

const CARD_W = 224;
const CARD_H = 100;
const GAP_X = 64;
const GAP_Y = 48;

function centerOf(item: PlacedNode): Point {
    return { x: item.x + CARD_W / 2, y: item.y + CARD_H / 2 };
}

/** Point on a card's border along the segment toward another point. */
function borderPoint(center: Point, toward: Point): Point {
    const dx = toward.x - center.x;
    const dy = toward.y - center.y;
    const adx = Math.abs(dx);
    const ady = Math.abs(dy);
    const t = Math.min(
        adx > 0 ? CARD_W / 2 / adx : Number.POSITIVE_INFINITY,
        ady > 0 ? CARD_H / 2 / ady : Number.POSITIVE_INFINITY,
    );
    return { x: center.x + dx * t, y: center.y + dy * t };
}

function layoutNodes(nodes: LayerNode[]): { items: PlacedNode[]; width: number; height: number } {
    if (nodes.length === 0) {
        return { items: [], width: CARD_W * 2, height: CARD_H * 2 };
    }

    const cols = Math.max(1, Math.min(3, Math.ceil(Math.sqrt(nodes.length))));
    const items = nodes.map((node, index) => ({
        node,
        x: (index % cols) * (CARD_W + GAP_X),
        y: Math.floor(index / cols) * (CARD_H + GAP_Y),
    }));
    const rows = Math.ceil(nodes.length / cols);

    return {
        items,
        width: cols * CARD_W + (cols - 1) * GAP_X,
        height: rows * CARD_H + (rows - 1) * GAP_Y,
    };
}

function arrowPolygon(from: Point, to: Point): string {
    const angle = Math.atan2(to.y - from.y, to.x - from.x);
    const size = 11;
    const spread = 0.45;
    const wingA = {
        x: to.x - size * Math.cos(angle - spread),
        y: to.y - size * Math.sin(angle - spread),
    };
    const wingB = {
        x: to.x - size * Math.cos(angle + spread),
        y: to.y - size * Math.sin(angle + spread),
    };
    return `${to.x},${to.y} ${wingA.x},${wingA.y} ${wingB.x},${wingB.y}`;
}

function suggestForeignKey(parent: LayerNode, child: LayerNode, relationships: RelationshipRecord[]): string {
    const onChild = relationships.filter((relationship) => relationship.related_layer_id === child.id);
    const base = `${parent.key_stem}_id`;

    if (onChild.some((relationship) => relationship.layer_id === parent.id && relationship.foreign_key === base)) {
        return base;
    }

    let candidate = base;
    let suffix = 1;
    while (onChild.some((relationship) => relationship.foreign_key === candidate)) {
        candidate = `${base}_${suffix++}`;
    }

    return candidate;
}

function suggestName(parent: LayerNode, child: LayerNode, relationships: RelationshipRecord[]): string {
    const base = `${parent.key_stem.charAt(0).toUpperCase()}${parent.key_stem.slice(1)} ${child.name}`;
    const taken = relationships.filter((relationship) => relationship.layer_id === parent.id).map((relationship) => relationship.name);

    if (!taken.includes(base)) {
        return base;
    }

    let suffix = 2;
    while (taken.includes(`${base} ${suffix}`)) {
        suffix += 1;
    }

    return `${base} ${suffix}`;
}

export default function Builder({ layers = [], relationships = [] }: { layers?: LayerNode[]; relationships?: RelationshipRecord[] }) {
    const { abilities } = usePage().props as { abilities?: { edit?: boolean } };
    const canEdit = Boolean(abilities?.edit);

    const containerRef = useRef<HTMLDivElement | null>(null);
    const [drag, setDrag] = useState<{ fromId: number; origin: Point; cursor: Point } | null>(null);
    const [targetId, setTargetId] = useState<number | null>(null);
    const [armedId, setArmedId] = useState<number | null>(null);
    const [pending, setPending] = useState<{ parent: LayerNode; child: LayerNode } | null>(null);
    const [name, setName] = useState('');
    const [foreignKey, setForeignKey] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const byId = useMemo(() => new Map(layers.map((layer) => [layer.id, layer])), [layers]);
    const placed = useMemo(() => layoutNodes(layers), [layers]);
    const positions = useMemo(() => new Map(placed.items.map((item) => [item.node.id, item])), [placed]);

    const visibleRelationships = relationships.filter(
        (relationship) => positions.has(relationship.layer_id) && positions.has(relationship.related_layer_id),
    );

    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'X-Requested-With': 'XMLHttpRequest',
    };

    useEffect(() => {
        const parent = Number(new URLSearchParams(window.location.search).get('parent'));
        if (parent && byId.has(parent)) {
            setArmedId(parent);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!drag) {
            return;
        }

        function move(event: PointerEvent) {
            const rect = containerRef.current?.getBoundingClientRect();
            if (!rect) {
                return;
            }
            setDrag((current) =>
                current ? { ...current, cursor: { x: event.clientX - rect.left, y: event.clientY - rect.top } } : current,
            );

            const element = document.elementFromPoint(event.clientX, event.clientY);
            const card = element?.closest('[data-layer-id]') as HTMLElement | null;
            const target = card ? Number(card.dataset.layerId) : null;
            setTargetId(target !== null && !Number.isNaN(target) && target !== drag.fromId ? target : null);
        }

        function up(event: PointerEvent) {
            const element = document.elementFromPoint(event.clientX, event.clientY);
            const card = element?.closest('[data-layer-id]') as HTMLElement | null;
            const target = card ? Number(card.dataset.layerId) : null;
            const fromId = drag.fromId;

            setDrag(null);
            setTargetId(null);

            if (target !== null && !Number.isNaN(target) && target !== fromId) {
                const parent = byId.get(fromId);
                const child = byId.get(target);
                if (parent && child) {
                    setArmedId(null);
                    setPending({ parent, child });
                    setName(suggestName(parent, child, relationships));
                    setForeignKey(suggestForeignKey(parent, child, relationships));
                    setErrors({});
                }
            }
        }

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);

        return () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [drag, byId, relationships]);

    function startDrag(event: ReactPointerEvent<HTMLDivElement>, item: PlacedNode) {
        if (!canEdit || event.button !== 0) {
            return;
        }
        event.preventDefault();
        const rect = containerRef.current?.getBoundingClientRect();
        const origin = centerOf(item);
        setDrag({
            fromId: item.node.id,
            origin,
            cursor: rect ? { x: event.clientX - rect.left, y: event.clientY - rect.top } : origin,
        });
        setTargetId(null);
    }

    const duplicate = pending
        ? relationships.find(
              (relationship) =>
                  relationship.layer_id === pending.parent.id && relationship.related_layer_id === pending.child.id,
          )
        : undefined;

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!pending || duplicate) {
            return;
        }

        setSaving(true);
        setErrors({});

        const nextErrors: Record<string, string[]> = {};
        if (name.trim() === '') {
            nextErrors.name = ['Give the relationship a name.'];
        }
        if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(foreignKey)) {
            nextErrors.foreign_key = ['Use letters, numbers, and underscores, starting with a letter or underscore.'];
        }
        if (Object.keys(nextErrors).length > 0) {
            setErrors(nextErrors);
            setSaving(false);
            return;
        }

        try {
            const response = await fetch(`/api/layers/${pending.parent.id}/relationships`, {
                method: 'POST',
                headers,
                body: JSON.stringify({
                    name: name.trim(),
                    related_layer_id: pending.child.id,
                    foreign_key: foreignKey.trim(),
                }),
            });
            const data = await response.json().catch(() => ({}) as Record<string, unknown>);

            if (!response.ok) {
                const mapped: Record<string, string[]> = {};
                Object.entries((data.errors as Record<string, unknown>) ?? {}).forEach(([key, value]) => {
                    mapped[key] = Array.isArray(value) ? (value as string[]) : [String(value)];
                });
                if (Object.keys(mapped).length === 0) {
                    mapped.name = [String(data.message || 'Could not save the relationship.')];
                }
                setErrors(mapped);
                return;
            }

            setPending(null);
            router.reload({ only: ['relationships'] });
        } catch (error) {
            setErrors({ name: [error instanceof Error ? error.message : 'Could not save the relationship.'] });
        } finally {
            setSaving(false);
        }
    }

    const armed = armedId ? byId.get(armedId) : undefined;
    const dragFrom = drag ? positions.get(drag.fromId) : undefined;

    return (
        <AppLayout title="Relationship builder">
            <PageHeader
                title="Relationship builder"
                action={<GhostLink href="/layers">Back to layers</GhostLink>}
            />

            <Panel>
                <p className="max-w-prose text-sm text-muted-foreground">
                    {canEdit
                        ? 'Drag from one layer card onto another to connect them. The name and key column are filled in for you — edit them if you need to.'
                        : 'Layers in this organization and the relationships between them. Editing relationships requires an editor or admin role.'}
                </p>

                {armed ? (
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-primary/40 bg-primary/5 px-3 py-2 text-sm">
                        <span>
                            Connecting from <span className="font-medium text-foreground">{armed.name}</span> — drag this
                            card onto the layer that should hold the key column.
                        </span>
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground"
                            onClick={() => setArmedId(null)}
                        >
                            Dismiss
                        </button>
                    </div>
                ) : null}

                {layers.length === 0 ? (
                    <div className="mt-4">
                        <Empty>
                            No layers with a table yet. Import data or create a layer first.
                            <GhostLink href="/imports" className="ml-2">
                                Go to imports
                            </GhostLink>
                        </Empty>
                    </div>
                ) : (
                    <div className="mt-4 overflow-auto rounded-md border border-border bg-muted/30 p-6">
                        <div
                            ref={containerRef}
                            className="relative"
                            style={{ width: placed.width, height: placed.height }}
                        >
                            <svg
                                className="pointer-events-none absolute inset-0"
                                width={placed.width}
                                height={placed.height}
                                aria-hidden="true"
                            >
                                {visibleRelationships.map((relationship) => {
                                    const from = positions.get(relationship.layer_id);
                                    const to = positions.get(relationship.related_layer_id);
                                    if (!from || !to) {
                                        return null;
                                    }
                                    const start = borderPoint(centerOf(from), centerOf(to));
                                    const end = borderPoint(centerOf(to), centerOf(from));
                                    return (
                                        <g key={relationship.id} className="text-muted-foreground">
                                            <line
                                                x1={start.x}
                                                y1={start.y}
                                                x2={end.x}
                                                y2={end.y}
                                                stroke="currentColor"
                                                strokeWidth={1.5}
                                                strokeDasharray="5 4"
                                            />
                                            <polygon points={arrowPolygon(start, end)} fill="currentColor" />
                                        </g>
                                    );
                                })}

                                {drag && dragFrom ? (
                                    <g className="text-primary">
                                        <line
                                            x1={drag.origin.x}
                                            y1={drag.origin.y}
                                            x2={drag.cursor.x}
                                            y2={drag.cursor.y}
                                            stroke="currentColor"
                                            strokeWidth={2}
                                        />
                                        <circle cx={drag.cursor.x} cy={drag.cursor.y} r={4} fill="currentColor" />
                                    </g>
                                ) : null}
                            </svg>

                            {visibleRelationships.map((relationship) => {
                                const from = positions.get(relationship.layer_id);
                                const to = positions.get(relationship.related_layer_id);
                                if (!from || !to) {
                                    return null;
                                }
                                const a = centerOf(from);
                                const b = centerOf(to);
                                return (
                                    <span
                                        key={`label-${relationship.id}`}
                                        className="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-1/2 rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                                        style={{ left: (a.x + b.x) / 2, top: (a.y + b.y) / 2 }}
                                    >
                                        {relationship.foreign_key}
                                    </span>
                                );
                            })}

                            {placed.items.map((item) => {
                                const isSource = drag?.fromId === item.node.id;
                                const isArmed = armedId === item.node.id;
                                const isTarget = drag && targetId === item.node.id;
                                return (
                                    <div
                                        key={item.node.id}
                                        data-layer-id={item.node.id}
                                        onPointerDown={(event) => startDrag(event, item)}
                                        className={[
                                            'absolute z-20 rounded-lg border bg-background p-3 shadow-sm',
                                            canEdit ? 'cursor-grab active:cursor-grabbing' : '',
                                            isSource ? 'border-primary ring-2 ring-primary' : '',
                                            !isSource && isArmed ? 'border-primary ring-1 ring-primary' : '',
                                            !isSource && drag && isTarget ? 'border-primary/60 ring-1 ring-primary/40' : '',
                                        ]
                                            .filter(Boolean)
                                            .join(' ')}
                                        style={{ left: item.x, top: item.y, width: CARD_W, height: CARD_H, touchAction: 'none' }}
                                    >
                                        <p className="truncate font-medium leading-tight">{item.node.name}</p>
                                        <p className="mt-1 truncate text-xs text-muted-foreground">
                                            {item.node.geometry_type || 'Attributes only'} · {item.node.feature_count}{' '}
                                            features
                                        </p>
                                        <p className="mt-1 truncate font-mono text-[11px] text-muted-foreground">
                                            {item.node.table_name}
                                        </p>
                                        {canEdit ? (
                                            <span className="pointer-events-none absolute -right-1.5 top-1/2 h-3 w-3 -translate-y-1/2 rounded-full border border-primary bg-background" />
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {layers.length === 1 ? (
                    <p className="mt-3 text-sm text-muted-foreground">
                        At least two layers are needed to create a relationship.
                    </p>
                ) : null}
            </Panel>

            <Dialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
                <DialogContent className="max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Connect layers</DialogTitle>
                        <DialogDescription>
                            The key column stores the parent feature id on the child table and is created when missing.
                        </DialogDescription>
                    </DialogHeader>

                    {pending ? (
                        <div className="flex items-center gap-3 text-sm">
                            <span className="rounded-md border border-border px-2 py-1 font-medium">
                                {pending.parent.name}
                            </span>
                            <span className="text-muted-foreground">&rarr;</span>
                            <span className="rounded-md border border-border px-2 py-1 font-medium">
                                {pending.child.name}
                            </span>
                        </div>
                    ) : null}

                    {duplicate ? (
                        <p className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
                            These layers are already connected as &ldquo;{duplicate.name}&rdquo; (
                            <span className="font-mono">{duplicate.foreign_key}</span>). Delete that relationship first
                            if you want a different key.
                        </p>
                    ) : null}

                    <form onSubmit={submit} className="grid gap-4">
                        <Field label="Name" error={errors.name} hint="How the relationship reads, e.g. Parcel buildings.">
                            <TextInput
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                disabled={Boolean(duplicate)}
                            />
                        </Field>

                        <Field
                            label="Key column"
                            error={errors.foreign_key}
                            hint="Column on the child table holding the parent feature id, e.g. parcel_id."
                        >
                            <TextInput
                                value={foreignKey}
                                onChange={(event) => setForeignKey(event.target.value)}
                                disabled={Boolean(duplicate)}
                            />
                        </Field>

                        <div className="flex justify-end gap-2 border-t pt-3">
                            <Button type="button" variant="outline" onClick={() => setPending(null)}>
                                Cancel
                            </Button>
                            <PrimaryButton type="submit" disabled={saving || Boolean(duplicate)}>
                                {saving ? 'Saving…' : 'Connect'}
                            </PrimaryButton>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
