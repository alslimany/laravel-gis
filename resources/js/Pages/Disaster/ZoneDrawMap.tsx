import { useEffect, useRef, useState } from 'react';
import OlMap from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import Draw from 'ol/interaction/Draw';
import { unByKey } from 'ol/Observable';
import { fromLonLat } from 'ol/proj';
import { Fill, Stroke, Style } from 'ol/style';
import 'ol/ol.css';
import { __ } from '@/lib/i18n';
import { localizedControls } from '@/lib/ol-controls';

export type ZonePolygon = { type: 'Polygon'; coordinates: number[][][] } | null;

/** Draw one evacuation zone polygon on a map (replaces the raw GeoJSON textarea). */
export default function ZoneDrawMap({
    value,
    onChange,
    height = 260,
}: {
    value: ZonePolygon;
    onChange: (zone: ZonePolygon) => void;
    height?: number;
}) {
    const ref = useRef<HTMLDivElement | null>(null);
    const sourceRef = useRef<VectorSource | null>(null);
    const [ready, setReady] = useState(false);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (!ref.current) {
            return;
        }
        const source = new VectorSource();
        sourceRef.current = source;
        const style = new Style({
            fill: new Fill({ color: 'rgba(220, 38, 38, 0.18)' }),
            stroke: new Stroke({ color: '#dc2626', width: 2 }),
        });
        const map = new OlMap({
            controls: localizedControls(),
            target: ref.current,
            layers: [new TileLayer({ source: new OSM() }), new VectorLayer({ source, style })],
            view: new View({ center: fromLonLat([13.19, 32.88]), zoom: 11 }),
        });
        const draw = new Draw({ source, type: 'Polygon' });
        draw.on('drawstart', () => source.clear());
        draw.on('drawend', (event) => {
            const geometry = new GeoJSON().writeGeometryObject(event.feature.getGeometry()!, {
                dataProjection: 'EPSG:4326',
                featureProjection: 'EPSG:3857',
                decimals: 6,
            }) as ZonePolygon;
            onChangeRef.current(geometry);
        });
        // Clicks before the first full render were lost: attach drawing only once the map is ready.
        let attached = false;
        const attach = () => {
            if (attached) {
                return;
            }
            attached = true;
            map.addInteraction(draw);
            setReady(true);
        };
        const renderKey = map.once('rendercomplete', attach);
        // Safety net if tiles are slow/offline: rendercomplete waits for every tile.
        const fallback = window.setTimeout(attach, 4000);

        return () => {
            window.clearTimeout(fallback);
            unByKey(renderKey);
            map.setTarget(undefined);
            sourceRef.current = null;
        };
    }, []);

    useEffect(() => {
        if (value === null) {
            sourceRef.current?.clear();
        }
    }, [value]);

    return (
        <div className="space-y-1">
            <div className="relative">
                <div ref={ref} style={{ height }} className="w-full overflow-hidden rounded-md border border-input" aria-busy={!ready} />
                {!ready ? (
                    <div className="absolute inset-0 flex items-center justify-center rounded-md bg-background/70 text-sm text-muted-foreground" role="status">
                        {__('disaster.evac.map_loading')}
                    </div>
                ) : null}
            </div>
            <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span>{!ready ? '' : value ? __('disaster.evac.zone_drawn') : __('disaster.evac.zone_draw_hint')}</span>
                {value ? (
                    <button type="button" className="underline" onClick={() => onChange(null)}>
                        {__('disaster.evac.zone_clear')}
                    </button>
                ) : null}
            </div>
        </div>
    );
}
