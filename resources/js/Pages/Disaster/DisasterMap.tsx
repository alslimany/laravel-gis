import { useEffect, useRef, useState } from 'react';
import OlMap from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorTileLayer from 'ol/layer/VectorTile';
import OSM from 'ol/source/OSM';
import VectorTileSource from 'ol/source/VectorTile';
import MVT from 'ol/format/MVT';
import { fromLonLat, transformExtent } from 'ol/proj';
import 'ol/ol.css';
// @ts-ignore plain JS module shared with the map builder
import { createStyleFunction } from '@/map-workspace/utils/styleRenderers';

import { __ } from '@/lib/i18n';
import { localizedControls } from '@/lib/ol-controls';
export type DisasterMapLayer = {
    id: number;
    key: string;
    title: string;
    geometry_type?: string | null;
    style_config?: Record<string, unknown> | null;
    legend: { value: string; color: string | null; label: string }[];
    bounds?: number[] | null;
    mvt_url: string;
};

export type DisasterMapConfig = {
    layers: DisasterMapLayer[];
    bounds?: number[] | null;
};

/** Org operational map for the COP and Assets pages: MVT tiles + layer styles + Arabic RTL legend. */
export default function DisasterMap({ config, className = '' }: { config?: DisasterMapConfig | null; className?: string }) {
    const target = useRef<HTMLDivElement | null>(null);
    const [hidden, setHidden] = useState<Record<number, boolean>>({});
    const olLayers = useRef<Record<number, VectorTileLayer>>({});
    const layers = config?.layers ?? [];

    useEffect(() => {
        if (!target.current) return undefined;
        const map = new OlMap({
            controls: localizedControls(),
            target: target.current,
            layers: [new TileLayer({ source: new OSM() })],
            view: new View({ center: fromLonLat([17, 27]), zoom: 5 }),
        });

        olLayers.current = {};
        layers.forEach((layer) => {
            const vt = new VectorTileLayer({
                source: new VectorTileSource({ format: new MVT(), url: layer.mvt_url }),
                style: createStyleFunction({ style_config: layer.style_config || {} }) || undefined,
                declutter: false,
            });
            olLayers.current[layer.id] = vt;
            map.addLayer(vt);
        });

        const b = config?.bounds;
        if (Array.isArray(b) && b.length === 4 && b.every((n) => Number.isFinite(Number(n)))) {
            const extent = transformExtent(b.map(Number), 'EPSG:4326', 'EPSG:3857');
            const fit = () => {
                try {
                    map.updateSize();
                    // OL padding is [top, right, bottom, left]. The canvas is dir="ltr", so the zoom
                    // buttons always sit top-left (also in Arabic); keep extra room there so the
                    // Tripoli points never land under them. maxZoom stops a tight cluster over-zooming.
                    map.getView().fit(extent, { padding: [56, 40, 40, 72], maxZoom: 13, duration: 0 });
                } catch {
                    // zero-sized container; keep default view
                }
            };
            window.requestAnimationFrame(fit);
            map.once('rendercomplete', fit);
        }

        return () => map.setTarget(undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config]);

    useEffect(() => {
        Object.entries(olLayers.current).forEach(([id, layer]) => layer.setVisible(!hidden[Number(id)]));
    }, [hidden]);

    return (
        <section className={`overflow-hidden rounded-lg border border-border ${className}`} data-testid="disaster-map">
            <div className="relative h-[420px] w-full">
                <div ref={target} className="h-full w-full" dir="ltr" />
                {layers.length === 0 && (
                    <div className="absolute inset-0 flex items-center justify-center bg-background/70 text-sm text-muted-foreground">
                        {__('disaster.map_no_layers')}
                    </div>
                )}
            </div>
            {layers.length > 0 && (
                <div className="grid gap-3 border-t border-border bg-muted/30 p-3 text-start text-xs sm:grid-cols-2 lg:grid-cols-4">
                    {layers.map((layer) => (
                        <div key={layer.id}>
                            <label className="mb-1 flex cursor-pointer items-center gap-2 font-semibold">
                                <input
                                    type="checkbox"
                                    checked={!hidden[layer.id]}
                                    onChange={() => setHidden((h) => ({ ...h, [layer.id]: !h[layer.id] }))}
                                />
                                {layer.title}
                            </label>
                            <ul className="space-y-1">
                                {layer.legend.map((entry) => (
                                    <li key={entry.value} className="flex items-center gap-2">
                                        <span
                                            className="inline-block h-3 w-3 rounded-sm border border-black/20"
                                            style={{ backgroundColor: entry.color || '#3388ff' }}
                                        />
                                        {entry.label}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
