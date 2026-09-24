import { router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { count, when } from '@/lib/format';
import { GhostLink, PageHeader, Panel, Pill, PrimaryButton, PrimaryLink } from '@/components/gis';

type LayerField = {
    id: number;
    name: string;
    alias?: string | null;
    type?: string | null;
};

type ShapeCount = { type: string; count: number };

type LayerRecord = {
    id: number;
    name: string;
    description?: string | null;
    published?: boolean;
    table_name?: string | null;
    geometry_type?: string | null;
    feature_count?: number | null;
    geoserver_layer_name?: string | null;
    geoserver_workspace?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    published_at?: string | null;
    style_config?: { renderer?: string; fill_color?: string; stroke_color?: string } | null;
    metadata?: { source?: string | null; import_id?: number | null } | null;
    project?: { name?: string | null } | null;
    user?: { name?: string | null } | null;
    organization?: { name?: string | null } | null;
    fields?: LayerField[];
};

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1 border-t py-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-baseline">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-all text-sm">{value}</dd>
        </div>
    );
}

export default function Show({ layer, shapes = [] }: { layer: LayerRecord; shapes?: ShapeCount[] }) {
    const { abilities } = usePage().props as { abilities?: { edit?: boolean } };
    const fields = layer.fields || [];
    const style = layer.style_config;
    const source = layer.metadata?.source;
    const importId = layer.metadata?.import_id;

    function publish() {
        router.post(layer.published ? `/layers/${layer.id}/unpublish` : `/layers/${layer.id}/publish`);
    }

    return (
        <AppLayout title={layer.name}>
            <PageHeader
                title={layer.name}
                action={
                    <div className="flex flex-wrap gap-2">
                        <GhostLink href="/layers">Back</GhostLink>
                        {abilities?.edit ? <GhostLink href={`/layers/${layer.id}/edit`}>Edit</GhostLink> : null}
                        <PrimaryLink href={`/maps/builder?layer=${layer.id}`}>Open in map</PrimaryLink>
                    </div>
                }
            />
            <Panel className="max-w-3xl">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Pill live={layer.published}>{layer.published ? 'Published' : 'Draft'}</Pill>
                    {abilities?.edit ? (
                        layer.published ? (
                            <Button type="button" variant="outline" onClick={publish}>
                                Unpublish
                            </Button>
                        ) : (
                            <PrimaryButton type="button" onClick={publish}>
                                Publish
                            </PrimaryButton>
                        )
                    ) : null}
                </div>
                <p className="mt-4 max-w-prose text-sm text-muted-foreground">
                    {layer.description || 'No description yet.'}
                </p>

                <section className="mt-6">
                    <h2 className="text-sm font-medium">Data</h2>
                    <dl>
                        <Fact label="Geometry" value={layer.geometry_type || 'Not detected'} />
                        <Fact label="Features" value={count(layer.feature_count)} />
                        <Fact
                            label="Shapes"
                            value={shapes.length ? shapes.map((shape) => `${shape.type} ${count(shape.count)}`).join(', ') : '—'}
                        />
                        <Fact label="Table" value={layer.table_name || '—'} />
                        <Fact label="Source" value={source ? source.toUpperCase() : '—'} />
                        <Fact label="Fields" value={fields.length ? fields.map((field) => field.alias || field.name).join(', ') : '—'} />
                    </dl>
                </section>

                <section className="mt-6">
                    <h2 className="text-sm font-medium">Publishing</h2>
                    <dl>
                        <Fact label="Status" value={layer.published ? 'Published to GeoServer' : 'Draft, not on GeoServer'} />
                        <Fact label="Workspace" value={layer.geoserver_workspace || '—'} />
                        <Fact label="GeoServer layer" value={layer.geoserver_layer_name || '—'} />
                        <Fact label="Published" value={when(layer.published_at)} />
                    </dl>
                </section>

                <section className="mt-6">
                    <h2 className="text-sm font-medium">Record</h2>
                    <dl>
                        <Fact label="Project" value={layer.project?.name || '—'} />
                        <Fact label="Organization" value={layer.organization?.name || '—'} />
                        <Fact label="Created by" value={layer.user?.name || '—'} />
                        <Fact label="Added" value={when(layer.created_at)} />
                        <Fact label="Updated" value={when(layer.updated_at)} />
                        <Fact
                            label="Style"
                            value={
                                style?.renderer
                                    ? [
                                          style.renderer,
                                          style.symbol?.icon && style.symbol.icon !== 'circle'
                                              ? style.symbol.icon
                                              : null,
                                          style.fill_color || style.symbol?.fillColor,
                                      ]
                                          .filter(Boolean)
                                          .join(', ')
                                    : '—'
                            }
                        />
                    </dl>
                </section>

                <div className="mt-6 flex flex-wrap gap-2 border-t pt-4">
                    <GhostLink href={`/layers/${layer.id}/attributes`}>Attributes</GhostLink>
                    {importId ? <GhostLink href={`/imports/${importId}`}>Import</GhostLink> : null}
                    <Button asChild variant="outline">
                        <a href={`/export/layer/${layer.id}/geojson`}>Download GeoJSON</a>
                    </Button>
                    <Button asChild variant="outline">
                        <a href={`/export/layer/${layer.id}/csv`}>Download CSV</a>
                    </Button>
                </div>
            </Panel>
        </AppLayout>
    );
}
