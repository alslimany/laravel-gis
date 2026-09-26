import { useEffect, useRef } from 'react';
import Map from 'ol/Map';
import View from 'ol/View';
import Feature from 'ol/Feature';
import Point from 'ol/geom/Point';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import { fromLonLat, toLonLat } from 'ol/proj';
import { Circle as CircleStyle, Fill, Stroke, Style } from 'ol/style';
import { Button } from '@/components/ui/button';
import 'ol/ol.css';

const DEFAULT_CENTER = fromLonLat([13.1913, 32.8872]);

function markerStyle() {
    const styles = getComputedStyle(document.documentElement);
    const fill = styles.getPropertyValue('--primary').trim() || '#111111';
    const stroke = styles.getPropertyValue('--background').trim() || '#ffffff';

    return new Style({
        image: new CircleStyle({
            radius: 7,
            fill: new Fill({ color: fill }),
            stroke: new Stroke({ color: stroke, width: 2 }),
        }),
    });
}

function place(source, longitude, latitude) {
    source.clear();
    source.addFeature(
        new Feature({
            geometry: new Point(fromLonLat([longitude, latitude])),
        }),
    );
}

export default function LocationPicker({ latitude, longitude, onPick }) {
    const host = useRef(null);
    const mapRef = useRef(null);
    const sourceRef = useRef(null);
    const onPickRef = useRef(onPick);
    onPickRef.current = onPick;

    useEffect(() => {
        const source = new VectorSource();
        sourceRef.current = source;
        const map = new Map({
            target: host.current,
            layers: [
                new TileLayer({ source: new OSM() }),
                new VectorLayer({ source, style: markerStyle() }),
            ],
            view: new View({ center: DEFAULT_CENTER, zoom: 6 }),
        });

        map.on('singleclick', (event) => {
            const [lon, lat] = toLonLat(event.coordinate);
            map.getView().animate({ center: event.coordinate, duration: 150 });
            onPickRef.current(lat.toFixed(6), lon.toFixed(6));
        });

        mapRef.current = map;
        requestAnimationFrame(() => map.updateSize());

        return () => {
            map.setTarget(null);
            mapRef.current = null;
            sourceRef.current = null;
        };
    }, []);

    useEffect(() => {
        const source = sourceRef.current;
        if (!source) {
            return;
        }

        const lat = Number(latitude);
        const lon = Number(longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon) || latitude === '' || longitude === '') {
            source.clear();
            return;
        }

        place(source, lon, lat);
    }, [latitude, longitude]);

    function locate() {
        if (!navigator.geolocation) {
            return;
        }

        navigator.geolocation.getCurrentPosition((position) => {
            const lat = position.coords.latitude.toFixed(6);
            const lon = position.coords.longitude.toFixed(6);
            mapRef.current?.getView().animate({
                center: fromLonLat([Number(lon), Number(lat)]),
                zoom: 14,
                duration: 200,
            });
            onPickRef.current(lat, lon);
        });
    }

    return (
        <div className="grid gap-2">
            <div
                ref={host}
                role="application"
                aria-label="Map. Click to choose a location."
                className="h-64 w-full overflow-hidden rounded-md border border-border"
            />
            <div>
                <Button type="button" variant="outline" onClick={locate}>
                    Use my location
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">Click the map to drop a point, or enter latitude and longitude.</p>
        </div>
    );
}
