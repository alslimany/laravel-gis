import { useEffect, useRef } from 'react';
import OlMap from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import { Fill, Stroke, Style } from 'ol/style';
import 'ol/ol.css';
import { __ } from '@/lib/i18n';
import { localizedControls } from '@/lib/ol-controls';

type Geometry = { type: string; coordinates: unknown } | null | undefined;

/** Small read-only map of an evacuation zone (replaces the raw GeoJSON dump). */
export default function ZonePreviewMap({ zone, height = 220 }: { zone: Geometry; height?: number }) {
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!ref.current || !zone) {
            return;
        }
        let features;
        try {
            features = new GeoJSON().readFeatures(
                { type: 'Feature', geometry: zone, properties: {} },
                { dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857' },
            );
        } catch {
            return;
        }
        const source = new VectorSource({ features });
        const map = new OlMap({
            target: ref.current,
            layers: [
                new TileLayer({ source: new OSM() }),
                new VectorLayer({
                    source,
                    style: new Style({
                        fill: new Fill({ color: 'rgba(220, 38, 38, 0.18)' }),
                        stroke: new Stroke({ color: '#b91c1c', width: 2 }),
                    }),
                }),
            ],
            view: new View({ center: [0, 0], zoom: 2 }),
            // Zoom buttons (localised, top-right in RTL); attribution stays collapsed by default.
            controls: localizedControls({ rotate: false }),
        });
        const extent = source.getExtent();
        if (extent && Number.isFinite(extent[0])) {
            map.getView().fit(extent, { padding: [16, 16, 16, 16], maxZoom: 15 });
        }

        return () => map.setTarget(undefined);
    }, [zone]);

    if (!zone) {
        return <p className="text-sm text-muted-foreground">{__('disaster.evac_zone_none')}</p>;
    }

    return (
        <div
            ref={ref}
            dir="ltr"
            role="img"
            aria-label={__('disaster.evac_zone')}
            className="w-full overflow-hidden rounded-md border border-border"
            style={{ height }}
        />
    );
}
