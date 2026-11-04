import { enumLabel } from '@/lib/enum-labels';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { useConsoleModules } from '@/lib/console-modules';
import { count, when } from '@/lib/format';
import { ModuleMark } from '@/components/module-switcher';
import { Empty, GhostLink, Heading, Panel, Pill, PrimaryLink, Table, DataTable, thClass, tdClass, tdMuted, tdMono } from '@/components/gis';

function lonLat(center) {
    if (!Array.isArray(center) || center.length < 2) {
        return [0, 20];
    }
    const x = Number(center[0]);
    const y = Number(center[1]);
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
        return [0, 20];
    }
    if (Math.abs(x) <= 180 && Math.abs(y) <= 90) {
        return [x, y];
    }
    const lon = (x / 20037508.34) * 180;
    const lat = (Math.atan(Math.exp((y / 20037508.34) * Math.PI)) * 360) / Math.PI - 90;
    return [lon, lat];
}

const TILE_CREDIT = {
    dark: ['CARTO', 'https://carto.com/attributions'],
    light: ['CARTO', 'https://carto.com/attributions'],
    terrain: ['OpenTopoMap', 'https://opentopomap.org'],
    imagery: ['Esri', 'https://www.esri.com/en-us/legal/terms/data-attributions'],
};

function tileCredit(basemap) {
    return TILE_CREDIT[basemap] || ['OpenStreetMap', 'https://www.openstreetmap.org/copyright'];
}

function tileUrl(basemap, z, x, y) {
    if (basemap === 'dark') return `https://a.basemaps.cartocdn.com/dark_all/${z}/${x}/${y}.png`;
    if (basemap === 'light') return `https://a.basemaps.cartocdn.com/light_all/${z}/${x}/${y}.png`;
    if (basemap === 'terrain') return `https://a.tile.opentopomap.org/${z}/${x}/${y}.png`;
    if (basemap === 'imagery') return `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${z}/${y}/${x}`;
    return `https://tile.openstreetmap.org/${z}/${x}/${y}.png`;
}

function MapFigure({ map, href }) {
    const { t } = useTranslation();

    if (!map) {
        return (
            <Link
                href={href || '/maps/builder'}
                className="flex h-48 items-center justify-center rounded border border-dashed border-border bg-muted/40 text-[13px] text-muted-foreground"
            >
                {__('dashboard.open_builder_hint')}
            </Link>
        );
    }

    const zoom = Math.min(12, Math.max(2, Math.round(Number(map.viewport?.zoom) || 3)));
    const [lon, lat] = lonLat(map.viewport?.center);
    const n = 2 ** zoom;
    const x = Math.floor(((lon + 180) / 360) * n);
    const latRad = (lat * Math.PI) / 180;
    const y = Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n);
    const [creditName, creditHref] = tileCredit(map.basemap);
    const tiles = [-1, 0].flatMap((dy) =>
        [-1, 0, 1].map((dx) => ({
            z: zoom,
            x: (((x + dx) % n) + n) % n,
            y: Math.min(n - 1, Math.max(0, y + dy)),
        })),
    );

    return (
        <figure className="overflow-hidden rounded border border-border bg-muted/40">
            <Link href={`/maps/${map.id}`} className="block" aria-label={map.name}>
                <span className="grid h-48 grid-cols-3 grid-rows-2" aria-hidden="true">
                    {tiles.map((tile) => (
                        <img key={`${tile.z}-${tile.x}-${tile.y}`} src={tileUrl(map.basemap, tile.z, tile.x, tile.y)} alt="" className="h-full w-full object-cover" />
                    ))}
                </span>
            </Link>
            <figcaption className="flex items-center justify-between gap-3 border-t border-border px-3 py-2">
                <span>
                    <span className="block text-[13px] font-medium">{map.name}</span>
                    <span className="text-[11px] text-muted-foreground">{map.is_public ? t('common.public') : t('common.private')}</span>
                </span>
                <Link href={`/maps/builder/${map.id}`} className="text-[13px] font-medium text-primary hover:underline">
                    {__('common.edit')}
                </Link>
            </figcaption>
            <p className="border-t border-border px-3 py-1.5 text-[11px] text-muted-foreground">
                {__('dashboard.tiles_from')}{' '}
                <a href={creditHref} className="underline" target="_blank" rel="noreferrer">
                    {creditName}
                </a>
            </p>
        </figure>
    );
}

function nextStep(stats, routes, t) {
    const imports = Number(stats?.import_count) || 0;
    const published = Number(stats?.published_layers) || 0;
    const totalLayers = Number(stats?.total_layers) || published;
    const maps = Number(stats?.total_maps) || 0;
    const drafts = Math.max(0, totalLayers - published);

    if (imports === 0 && totalLayers === 0) {
        return {
            title: __('dashboard.import_dataset'),
            detail: __('dashboard.import_detail'),
            href: routes.importsCreate || routes.imports || '/imports',
            cta: __('dashboard.import_data'),
            step: 1,
        };
    }
    if (drafts > 0 || (totalLayers > 0 && published === 0)) {
        return {
            title: __('dashboard.publish_layer'),
            detail: drafts > 0 ? __('dashboard.drafts_ready', { count: drafts }) : __('dashboard.publish_detail'),
            href: routes.layers || '/layers',
            cta: __('dashboard.open_layers'),
            step: 2,
        };
    }
    if (maps === 0) {
        return {
            title: __('dashboard.compose_map'),
            detail: __('dashboard.compose_detail'),
            href: routes.mapBuilder || '/maps/builder',
            cta: t('maps.open_builder'),
            step: 3,
        };
    }
    return {
        title: __('dashboard.continue_builder'),
        detail: __('dashboard.continue_detail'),
        href: routes.mapBuilder || '/maps/builder',
        cta: t('maps.open_builder'),
        step: 3,
    };
}

export default function Dashboard({ stats, projects }) {
    const { t } = useTranslation();
    const { organization, routes, abilities } = usePage().props;
    const { modules } = useConsoleModules();
    const featured = stats?.recent_maps?.[0] || null;
    const layers = stats?.recent_layers || [];
    const projectRows = projects?.data || [];
    const next = nextStep(stats, routes, t);
    const orgName = organization?.name || __('dashboard.your_org');

    return (
        <AppLayout title={t('dashboard.title')}>
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <Heading as="h1">{orgName}</Heading>
                    <p className="mt-1 text-[13px] text-muted-foreground">
                        {__('dashboard.flow_hint')}
                    </p>
                    <p className="mt-2 font-mono text-[12px] tabular-nums text-muted-foreground">
                        <Link href={routes.imports || '/imports'} className="font-medium text-primary hover:underline">
                            {__('dashboard.count_imports', { count: count(stats?.import_count) })}
                        </Link>
                        <span> · </span>
                        <Link href={routes.layers || '/layers'} className="font-medium text-primary hover:underline">
                            {__('dashboard.count_published', { count: count(stats?.published_layers) })}
                        </Link>
                        <span> · </span>
                        <Link href={routes.maps || '/maps'} className="font-medium text-primary hover:underline">
                            {__('dashboard.count_maps', { count: count(stats?.total_maps) })}
                        </Link>
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {routes.apps ? <GhostLink href={routes.apps}>{t('apps.browse')}</GhostLink> : null}
                    {routes.catalog ? <GhostLink href={routes.catalog}>{t('catalog.browse')}</GhostLink> : null}
                </div>
            </div>

            {modules.length > 1 ? (
                <section aria-labelledby="modules-heading">
                    <div className="mb-2 flex items-center justify-between gap-3">
                        <p dir="ltr" id="modules-heading" className="font-mono text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                            {__('dashboard.your_modules')}
                        </p>
                        {abilities?.admin && routes.extensions ? (
                            <Link href={routes.extensions} className="text-[13px] font-medium text-primary hover:underline">
                                {__('actions.manage_extensions')}
                            </Link>
                        ) : null}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {modules.map((module) => (
                            <Link
                                key={module.key}
                                href={module.href}
                                className="flex items-center gap-3 rounded border border-border px-3 py-3 transition-colors hover:bg-muted/50"
                            >
                                <ModuleMark module={module} />
                                <span className="min-w-0">
                                    <span className="block truncate text-[13px] font-medium">{module.label}</span>
                                    {module.description ? (
                                        <span className="block truncate text-[11px] text-muted-foreground">{module.description}</span>
                                    ) : null}
                                </span>
                            </Link>
                        ))}
                    </div>
                </section>
            ) : null}

            <section
                className="rounded border border-border bg-muted/40 px-4 py-4"
                aria-labelledby="next-step-heading"
            >
                <p className="font-mono text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                    {__('dashboard.next_step', { step: next.step })}
                </p>
                <Heading as="h2" id="next-step-heading" className="mt-1">
                    {next.title}
                </Heading>
                <p className="mt-1 max-w-xl text-[13px] text-muted-foreground">{next.detail}</p>
                <div className="mt-3">
                    <PrimaryLink href={next.href}>{next.cta}</PrimaryLink>
                </div>
            </section>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <Panel>
                    <div className="flex items-center justify-between border-b border-border px-3 py-2">
                        <Heading as="h2">{__('dashboard.recent_layers')}</Heading>
                        <Link href={routes.layers || '/layers'} className="text-[13px] font-medium text-primary hover:underline">
                            {__('dashboard.all_layers')}
                        </Link>
                    </div>
                    {layers.length ? (
                        <DataTable>
                            <Table.Header>
                                <Table.Row>
                                    <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                    <Table.Head className={`${thClass} hidden md:table-cell`}>{t('layers.geometry')}</Table.Head>
                                    <Table.Head className={`${thClass} hidden md:table-cell`}>{t('layers.features')}</Table.Head>
                                    <Table.Head className={thClass}>{t('forms.status')}</Table.Head>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {layers.map((layer) => (
                                    <Table.Row key={layer.id}>
                                        <Table.Cell className={tdClass}>
                                            <Link href={`/layers/${layer.id}`} className="font-medium text-primary hover:underline">
                                                {layer.name}
                                            </Link>
                                        </Table.Cell>
                                        <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{enumLabel(layer.geometry_type) || t('common.em_dash')}</Table.Cell>
                                        <Table.Cell className={`${tdMono} hidden md:table-cell`}>{count(layer.feature_count)}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            <Pill live={layer.published}>{layer.published ? t('layers.published') : __('layers.draft')}</Pill>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </DataTable>
                    ) : (
                        <Empty>
                            {__('dashboard.no_layers')}{' '}
                            <Link href={routes.imports || '/imports'} className="font-medium text-primary hover:underline">
                                {__('dashboard.import_dataset')}
                            </Link>
                        </Empty>
                    )}
                </Panel>

                <div className="space-y-4">
                    <MapFigure map={featured} href={routes.mapBuilder} />
                    <Panel>
                        <div className="flex items-center justify-between border-b border-border px-3 py-2">
                            <Heading as="h2">{t('projects.title')}</Heading>
                            <Link href={routes.projects || '/projects'} className="text-[13px] font-medium text-primary hover:underline">
                                {__('dashboard.all_projects')}
                            </Link>
                        </div>
                        {projectRows.length ? (
                            <ul>
                                {projectRows.map((project) => (
                                    <li key={project.id} className="flex items-center justify-between gap-3 border-b border-border px-3 py-2 last:border-b-0">
                                        <Link href={`/projects/${project.id}`} className="truncate text-[13px] font-medium text-primary hover:underline">
                                            {project.name}
                                        </Link>
                                        <span dir="ltr" className="shrink-0 font-mono text-[11px] text-muted-foreground">{project.created_at_formatted || when(project.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>{__('dashboard.no_projects')}</Empty>
                        )}
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}
