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

function tileIndex(lon, lat, zoom) {
    const n = 2 ** zoom;
    const x = Math.floor(((lon + 180) / 360) * n);
    const latRad = (lat * Math.PI) / 180;
    const y = Math.floor(
        ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n
    );

    return {
        x: ((x % n) + n) % n,
        y: Math.min(n - 1, Math.max(0, y)),
        n,
    };
}

function tileUrl(basemap, z, x, y) {
    if (basemap === 'dark') {
        return `https://a.basemaps.cartocdn.com/dark_all/${z}/${x}/${y}.png`;
    }
    if (basemap === 'light') {
        return `https://a.basemaps.cartocdn.com/light_all/${z}/${x}/${y}.png`;
    }
    if (basemap === 'terrain') {
        return `https://a.tile.opentopomap.org/${z}/${x}/${y}.png`;
    }
    if (basemap === 'imagery') {
        return `https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${z}/${y}/${x}`;
    }

    return `https://tile.openstreetmap.org/${z}/${x}/${y}.png`;
}

function MapTiles({ viewport, basemap }) {
    const zoom = Math.min(12, Math.max(2, Math.round(Number(viewport?.zoom) || 3)));
    const [lon, lat] = lonLat(viewport?.center);
    const { x, y, n } = tileIndex(lon, lat, zoom);
    const cols = [-1, 0, 1];
    const rows = [-1, 0];
    const tiles = rows.flatMap((dy) =>
        cols.map((dx) => {
            const tx = (((x + dx) % n) + n) % n;
            const ty = Math.min(n - 1, Math.max(0, y + dy));

            return { z: zoom, x: tx, y: ty };
        })
    );

    return (
        <span className="map-tiles" aria-hidden="true">
            {tiles.map((tile) => (
                <img
                    key={`${tile.z}-${tile.x}-${tile.y}`}
                    src={tileUrl(basemap, tile.z, tile.x, tile.y)}
                    alt=""
                    width="256"
                    height="256"
                    loading="lazy"
                    decoding="async"
                />
            ))}
        </span>
    );
}

function MapPresence({ map, routes, isNext }) {
    if (!map) {
        const href = routes?.mapBuilder || routes?.maps || '#';

        return (
            <a className={`map-frame map-frame-empty ${isNext ? 'is-next' : ''}`} href={href}>
                <span className="map-frame-copy">
                    <span className="pipeline-name">Map</span>
                    <span className="pipeline-detail">No map yet. Open the map builder.</span>
                </span>
            </a>
        );
    }

    const href = routes?.mapShow ? routes.mapShow.replace('__ID__', map.id) : routes?.maps || '#';
    const layerCount = Array.isArray(map.layers) ? map.layers.length : 0;

    return (
        <div className={`map-frame ${isNext ? 'is-next' : ''}`}>
            <a className="map-frame-link" href={href}>
                <MapTiles viewport={map.viewport} basemap={map.basemap} />
                <span className="map-frame-copy">
                    <span className="pipeline-name">Map</span>
                    <span className="map-frame-title">{map.name}</span>
                    <span className="pipeline-detail">
                        {layerCount} {layerCount === 1 ? 'layer' : 'layers'}
                        {map.is_public ? ' · public' : ''}
                    </span>
                </span>
            </a>
            <a
                className="map-attrib"
                href="https://www.openstreetmap.org/copyright"
                target="_blank"
                rel="noreferrer"
            >
                © OpenStreetMap
            </a>
        </div>
    );
}

function LayerTable({ layers, routes }) {
    if (!layers.length) {
        return (
            <p className="desk-empty">
                No layers in this organization.
                {routes?.importsCreate ? (
                    <>
                        {' '}
                        <a href={routes.importsCreate}>Import a dataset</a> to start the inventory.
                    </>
                ) : null}
            </p>
        );
    }

    return (
        <div className="desk-table-wrap">
            <table className="desk-table">
                <thead>
                    <tr>
                        <th>Layer</th>
                        <th className="desk-hide-sm">Geometry</th>
                        <th className="num desk-hide-sm">Features</th>
                        <th className="desk-status">Status</th>
                    </tr>
                </thead>
                <tbody>
                    {layers.map((layer) => {
                        const href = routes?.layerShow
                            ? routes.layerShow.replace('__ID__', layer.id)
                            : null;
                        return (
                            <tr key={layer.id}>
                                <td>
                                    {href ? (
                                        <a href={href} className="desk-name">
                                            {layer.name}
                                        </a>
                                    ) : (
                                        layer.name
                                    )}
                                    <span className="desk-sub">{layer.created_human}</span>
                                </td>
                                <td className="mono desk-hide-sm">{layer.geometry_type || '—'}</td>
                                <td className="num mono desk-hide-sm">
                                    {Number(layer.feature_count || 0).toLocaleString()}
                                </td>
                                <td className="desk-status">
                                    <span className={layer.published ? 'pill pill-live' : 'pill'}>
                                        {layer.published ? 'Published' : 'Draft'}
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

export default function DashboardHome({ organization, stats, projects, routes, flash }) {
    const recentLayers = stats?.recent_layers || [];
    const featuredMap = (stats?.recent_maps || [])[0] || null;
    const projectRows = projects?.data || projects || [];
    const imports = Number(stats?.import_count || 0);
    const layers = Number(stats?.total_layers || 0);
    const published = Number(stats?.published_layers || 0);
    const maps = Number(stats?.total_maps || 0);
    const next =
        imports === 0 && layers === 0 ? 'import' : published === 0 ? 'publish' : maps === 0 ? 'map' : null;
    const importHref = next === 'import' && routes?.importsCreate ? routes.importsCreate : routes?.imports;
    const publishHref = routes?.layers;

    return (
        <div className="desk">
            <header className="desk-intro">
                <h1>{organization?.name || 'Unassigned'}</h1>
            </header>

            {flash?.status ? <p className="desk-flash">{flash.status}</p> : null}

            {!organization ? (
                <p className="desk-empty desk-empty-block">
                    You are not in an organization. Ask an administrator to assign you before you
                    can open layers or maps.
                </p>
            ) : (
                <>
                    <ol className="pipeline">
                        <li className={next === 'import' ? 'is-next' : ''}>
                            {importHref ? (
                                <a href={importHref}>
                                    <span className="pipeline-name">Import</span>
                                    <span className="pipeline-detail">
                                        {imports === 0
                                            ? 'No dataset yet'
                                            : `${imports} ${imports === 1 ? 'dataset' : 'datasets'}`}
                                    </span>
                                </a>
                            ) : (
                                <div>
                                    <span className="pipeline-name">Import</span>
                                    <span className="pipeline-detail">
                                        {imports === 0 ? 'No dataset yet' : `${imports} datasets`}
                                    </span>
                                </div>
                            )}
                        </li>
                        <li className={next === 'publish' ? 'is-next' : ''}>
                            {publishHref ? (
                                <a href={publishHref}>
                                    <span className="pipeline-name">Publish</span>
                                    <span className="pipeline-detail">
                                        {published === 0
                                            ? 'Nothing published'
                                            : `${published} of ${layers} published`}
                                    </span>
                                </a>
                            ) : (
                                <div>
                                    <span className="pipeline-name">Publish</span>
                                    <span className="pipeline-detail">
                                        {published === 0 ? 'Nothing published' : `${published} published`}
                                    </span>
                                </div>
                            )}
                        </li>
                        <li className="pipeline-map">
                            <MapPresence map={featuredMap} routes={routes} isNext={next === 'map'} />
                            {maps > 1 && routes?.maps ? (
                                <a className="pipeline-more" href={routes.maps}>
                                    All maps
                                </a>
                            ) : null}
                        </li>
                    </ol>

                    <section className="desk-panel">
                        <div className="desk-panel-head">
                            <h2>Layers</h2>
                            {routes?.layers ? <a href={routes.layers}>All layers</a> : null}
                        </div>
                        <LayerTable layers={recentLayers} routes={routes} />
                    </section>

                    {projectRows.length > 0 ? (
                        <section className="desk-panel">
                            <div className="desk-panel-head">
                                <h2>Projects</h2>
                                {routes?.projects ? <a href={routes.projects}>All projects</a> : null}
                            </div>
                            <div className="desk-table-wrap">
                                <table className="desk-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th className="desk-hide-sm">Description</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {projectRows.map((project) => {
                                            const href = routes?.projectShow
                                                ? routes.projectShow.replace('__ID__', project.id)
                                                : null;

                                            return (
                                            <tr key={project.id}>
                                                <td>
                                                    {href ? (
                                                        <a href={href} className="desk-name">
                                                            {project.name}
                                                        </a>
                                                    ) : (
                                                        <span className="desk-name">{project.name}</span>
                                                    )}
                                                </td>
                                                <td className="desk-sub desk-sub-inline desk-hide-sm">
                                                    {project.description || '—'}
                                                </td>
                                                <td className="mono">
                                                    {project.created_at_formatted || ''}
                                                </td>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ) : null}
                </>
            )}
        </div>
    );
}
