import { router, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { count, when } from '@/lib/format';
import { enumLabel } from '@/lib/enum-labels';
import { layerDescription } from '@/lib/layer-descriptions';
import { GhostLink, PageHeader, Panel, Pill, PrimaryButton, PrimaryLink } from '@/components/gis';

type LayerField = {
    id: number;
    name: string;
    alias?: string | null;
    type?: string | null;
};

type ShapeCount = { type: string; count: number };

type Origin = { kind: string; label: string; href?: string | null };

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
    metadata?: { source?: string | null; import_id?: number | null; description_key?: string | null } | null;
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

export default function Show({ layer, shapes = [], origin = null }: { layer: LayerRecord; shapes?: ShapeCount[]; origin?: Origin | null }) {
    const { t } = useTranslation();
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
                        <GhostLink href="/layers">{t('actions.back')}</GhostLink>
                        {abilities?.edit ? <GhostLink href={`/layers/${layer.id}/edit`}>{t('actions.edit')}</GhostLink> : null}
                        {abilities?.edit && layer.geometry_type !== 'Raster' ? (
                            <PrimaryLink href={`/layers/${layer.id}/builder`}>{t('layers.builder')}</PrimaryLink>
                        ) : null}
                        <GhostLink href={`/maps/builder?layer=${layer.id}`}>{t('layers.open_in_map')}</GhostLink>
                    </div>
                }
            />
            <Panel className="max-w-3xl">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Pill live={layer.published}>{layer.published ? t('layers.published') : __('layers.idx.draft')}</Pill>
                    {abilities?.edit ? (
                        layer.published ? (
                            <Button type="button" variant="outline" onClick={publish}>
                                {__('layers.show.unpublish')}
                            </Button>
                        ) : (
                            <PrimaryButton type="button" onClick={publish}>
                                {__('layers.show.publish')}
                            </PrimaryButton>
                        )
                    ) : null}
                </div>
                <p className="mt-4 max-w-prose text-sm text-muted-foreground">
                    {layerDescription(layer.description, layer.metadata) || __('layers.show.no_description')}
                </p>

                {origin ? (
                    <p className="mt-2 text-sm text-muted-foreground">
                        {__('layers.show.created_from')}{' '}
                        {origin.kind === 'analysis' ? __('layers.show.from_analysis') : origin.kind === 'import' ? __('layers.show.from_import') : origin.kind}{' '}
                        <span className="font-medium text-foreground">{origin.label}</span>
                        {origin.href ? (
                            <>
                                {' · '}
                                <a href={origin.href} className="font-medium text-primary hover:underline">
                                    {origin.kind === 'analysis' ? __('layers.show.open_source') : __('layers.show.open_import')}
                                </a>
                            </>
                        ) : null}
                    </p>
                ) : null}

                <section className="mt-6">
                    <h2 className="text-sm font-medium">{t('nav.sections.data')}</h2>
                    <dl>
                        <Fact label={t('layers.geometry')} value={layer.geometry_type ? enumLabel(layer.geometry_type) : t('layers.not_detected')} />
                        <Fact label={t('layers.features')} value={count(layer.feature_count)} />
                        <Fact
                            label={t('layers.shapes')}
                            value={shapes.length ? shapes.map((shape) => `${enumLabel(shape.type)} ${count(shape.count)}`).join(', ') : t('common.em_dash')}
                        />
                        {layer.table_name ? <Fact label={t('layers.table')} value={layer.table_name} /> : null}
                        <Fact label={t('layers.source')} value={source ? source.toUpperCase() : t('common.em_dash')} />
                        <Fact label={t('layers.fields')} value={fields.length ? fields.map((field) => field.alias || field.name).join(', ') : t('common.em_dash')} />
                    </dl>
                </section>

                <section className="mt-6">
                    <h2 className="text-sm font-medium">{__('layers.show.publishing')}</h2>
                    <dl>
                        <Fact label={t('forms.status')} value={layer.published ? t('layers.published_geoserver') : t('layers.draft_not_geoserver')} />
                        {layer.geoserver_workspace ? <Fact label={t('layers.workspace')} value={layer.geoserver_workspace} /> : null}
                        {layer.geoserver_layer_name ? <Fact label={t('layers.geoserver_layer')} value={layer.geoserver_layer_name} /> : null}
                        <Fact label={t('layers.published')} value={when(layer.published_at)} />
                    </dl>
                </section>

                <section className="mt-6">
                    <h2 className="text-sm font-medium">{__('layers.show.record')}</h2>
                    <dl>
                        <Fact label={t('layers.project')} value={layer.project?.name || t('common.em_dash')} />
                        <Fact label={t('organization.title')} value={layer.organization?.name || t('common.em_dash')} />
                        <Fact label={t('forms.created_by')} value={layer.user?.name || t('common.em_dash')} />
                        <Fact label={t('layers.added')} value={when(layer.created_at)} />
                        <Fact label={t('layers.updated')} value={when(layer.updated_at)} />
                        <Fact
                            label={t('layers.style')}
                            value={
                                style?.renderer
                                    ? [
                                          __(`maps.renderer.${style.renderer}`, undefined, style.renderer),
                                          style.symbol?.icon && style.symbol.icon !== 'circle'
                                              ? style.symbol.icon
                                              : null,
                                          style.fill_color || style.symbol?.fillColor,
                                      ]
                                          .filter(Boolean)
                                          .join(', ')
                                    : t('common.em_dash')
                            }
                        />
                    </dl>
                </section>

                <div className="mt-6 flex flex-wrap gap-2 border-t pt-4">
                    <GhostLink href={`/layers/${layer.id}/fields`}>{t('layers.fields')}</GhostLink>
                    <GhostLink href={`/layers/${layer.id}/relationships`}>{t('relationships.title')}</GhostLink>
                    <GhostLink href={`/layers/${layer.id}/attributes`}>{t('layers.attributes')}</GhostLink>
                    {importId ? <GhostLink href={`/imports/${importId}`}>{__('layers.show.import')}</GhostLink> : null}
                    <Button asChild variant="outline">
                        <a href={`/export/layer/${layer.id}/geojson`}>{t('exports.download_geojson')}</a>
                    </Button>
                    <Button asChild variant="outline">
                        <a href={`/export/layer/${layer.id}/csv`}>{t('exports.download_csv')}</a>
                    </Button>
                </div>
            </Panel>
        </AppLayout>
    );
}
