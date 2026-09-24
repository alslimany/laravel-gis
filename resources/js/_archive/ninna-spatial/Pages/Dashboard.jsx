import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { count, when } from '../lib/format';
import { Empty, GhostLink, Heading, Panel, Pill, PrimaryLink, Table, DataTable, thClass, tdClass, tdMuted, tdMono } from '../Components/ui';

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
    if (!map) {
        return (
            <Link
                href={href || '/maps/builder'}
                className="flex h-48 items-center justify-center rounded border border-dashed border-border bg-base-50 text-[13px] text-base-600"
            >
                Open the map builder to compose a map
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
        <figure className="overflow-hidden rounded border border-border bg-base-50">
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
                    <span className="text-[11px] text-base-600">{map.is_public ? 'Public' : 'Private'}</span>
                </span>
                <Link href={`/maps/builder/${map.id}`} className="text-[13px] font-medium text-primary hover:underline">
                    Edit
                </Link>
            </figcaption>
            <p className="border-t border-border px-3 py-1.5 text-[11px] text-base-600">
                Tiles from{' '}
                <a href={creditHref} className="underline" target="_blank" rel="noreferrer">
                    {creditName}
                </a>
            </p>
        </figure>
    );
}

function nextStep(stats, routes) {
    const imports = Number(stats?.import_count) || 0;
    const published = Number(stats?.published_layers) || 0;
    const totalLayers = Number(stats?.total_layers) || published;
    const maps = Number(stats?.total_maps) || 0;
    const drafts = Math.max(0, totalLayers - published);

    if (imports === 0 && totalLayers === 0) {
        return {
            title: 'Import a dataset',
            detail: 'Bring spatial files or tables into this organization to start the loop.',
            href: routes.importsCreate || routes.imports || '/imports',
            cta: 'Import data',
            step: 1,
        };
    }
    if (drafts > 0 || (totalLayers > 0 && published === 0)) {
        return {
            title: 'Publish a layer',
            detail: drafts > 0 ? `${drafts} draft layer${drafts === 1 ? '' : 's'} ready to publish.` : 'Publish a layer so maps and analysis can use it.',
            href: routes.layers || '/layers',
            cta: 'Open layers',
            step: 2,
        };
    }
    if (maps === 0) {
        return {
            title: 'Compose a map',
            detail: 'Published layers are ready. Open the map builder and compose the first map.',
            href: routes.mapBuilder || '/maps/builder',
            cta: 'Open map builder',
            step: 3,
        };
    }
    return {
        title: 'Continue in the map builder',
        detail: 'Data is published. Keep editing maps, or open dashboards when you need a board.',
        href: routes.mapBuilder || '/maps/builder',
        cta: 'Open map builder',
        step: 3,
    };
}

export default function Dashboard({ stats, projects }) {
    const { organization, routes } = usePage().props;
    const featured = stats?.recent_maps?.[0] || null;
    const layers = stats?.recent_layers || [];
    const projectRows = projects?.data || [];
    const next = nextStep(stats, routes);
    const orgName = organization?.name || 'your organization';

    return (
        <AppLayout title="Dashboard">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <Heading as="h1">{orgName}</Heading>
                    <p className="mt-1 text-[13px] text-base-600">
                        Import → publish → map. Counts stay visible while you work the next step.
                    </p>
                    <p className="mt-2 font-mono text-[12px] tabular-nums text-base-600">
                        <Link href={routes.imports || '/imports'} className="font-medium text-primary hover:underline">
                            {count(stats?.import_count)} imports
                        </Link>
                        <span> · </span>
                        <Link href={routes.layers || '/layers'} className="font-medium text-primary hover:underline">
                            {count(stats?.published_layers)} published
                        </Link>
                        <span> · </span>
                        <Link href={routes.maps || '/maps'} className="font-medium text-primary hover:underline">
                            {count(stats?.total_maps)} maps
                        </Link>
                    </p>
                </div>
                {routes.catalog ? <GhostLink href={routes.catalog}>Browse catalog</GhostLink> : null}
            </div>

            <section
                className="rounded border border-border bg-base-50 px-4 py-4"
                aria-labelledby="next-step-heading"
            >
                <p className="font-mono text-[10px] font-medium uppercase tracking-wider text-base-600">
                    Next · step {next.step} of 3
                </p>
                <Heading as="h2" id="next-step-heading" className="mt-1">
                    {next.title}
                </Heading>
                <p className="mt-1 max-w-xl text-[13px] text-base-600">{next.detail}</p>
                <div className="mt-3">
                    <PrimaryLink href={next.href}>{next.cta}</PrimaryLink>
                </div>
            </section>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <Panel>
                    <div className="flex items-center justify-between border-b border-border px-3 py-2">
                        <Heading as="h2">Recent layers</Heading>
                        <Link href={routes.layers || '/layers'} className="text-[13px] font-medium text-primary hover:underline">
                            All layers
                        </Link>
                    </div>
                    {layers.length ? (
                        <DataTable>
                            <Table.Header>
                                <Table.Row>
                                    <Table.Head className={thClass}>Name</Table.Head>
                                    <Table.Head className={`${thClass} hidden md:table-cell`}>Geometry</Table.Head>
                                    <Table.Head className={`${thClass} hidden md:table-cell`}>Features</Table.Head>
                                    <Table.Head className={thClass}>Status</Table.Head>
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
                                        <Table.Cell className={`${tdMuted} hidden md:table-cell`}>{layer.geometry_type || '—'}</Table.Cell>
                                        <Table.Cell className={`${tdMono} hidden md:table-cell`}>{count(layer.feature_count)}</Table.Cell>
                                        <Table.Cell className={tdClass}>
                                            <Pill live={layer.published}>{layer.published ? 'Published' : 'Draft'}</Pill>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </DataTable>
                    ) : (
                        <Empty>
                            No layers yet.{' '}
                            <Link href={routes.imports || '/imports'} className="font-medium text-primary hover:underline">
                                Import a dataset
                            </Link>
                        </Empty>
                    )}
                </Panel>

                <div className="space-y-4">
                    <MapFigure map={featured} href={routes.mapBuilder} />
                    <Panel>
                        <div className="flex items-center justify-between border-b border-border px-3 py-2">
                            <Heading as="h2">Projects</Heading>
                            <Link href={routes.projects || '/projects'} className="text-[13px] font-medium text-primary hover:underline">
                                All projects
                            </Link>
                        </div>
                        {projectRows.length ? (
                            <ul>
                                {projectRows.map((project) => (
                                    <li key={project.id} className="flex items-center justify-between gap-3 border-b border-border px-3 py-2 last:border-b-0">
                                        <Link href={`/projects/${project.id}`} className="truncate text-[13px] font-medium text-primary hover:underline">
                                            {project.name}
                                        </Link>
                                        <span className="shrink-0 font-mono text-[11px] text-base-600">{project.created_at_formatted || when(project.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty>No projects in this organization.</Empty>
                        )}
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}
