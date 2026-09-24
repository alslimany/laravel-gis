import { useEffect, useRef } from 'react';
import Map from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorTileLayer from 'ol/layer/VectorTile';
import VectorLayer from 'ol/layer/Vector';
import XYZ from 'ol/source/XYZ';
import OSM from 'ol/source/OSM';
import TileWMS from 'ol/source/TileWMS';
import VectorTileSource from 'ol/source/VectorTile';
import VectorSource from 'ol/source/Vector';
import MVT from 'ol/format/MVT';
import GeoJSON from 'ol/format/GeoJSON';
import { fromLonLat } from 'ol/proj';
import { Fill, Stroke, Style } from 'ol/style';
import 'ol/ol.css';
import { attachTileCache } from '../map-workspace/cache/tileCache';

function basemapSource(id) {
    if (id === 'imagery') {
        return attachTileCache(
            new XYZ({
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                maxZoom: 19,
            }),
            'imagery',
        );
    }
    if (id === 'dark') {
        return attachTileCache(new XYZ({ url: 'https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', maxZoom: 19 }), 'dark');
    }
    if (id === 'light') {
        return attachTileCache(new XYZ({ url: 'https://a.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', maxZoom: 19 }), 'light');
    }
    if (id === 'terrain') {
        return attachTileCache(new XYZ({ url: 'https://a.tile.opentopomap.org/{z}/{x}/{y}.png', maxZoom: 17 }), 'terrain');
    }
    return attachTileCache(new OSM(), id || 'osm');
}

export default function SharedMap({ map: mapRecord }) {
    const target = useRef(null);

    useEffect(() => {
        if (!target.current) {
            return undefined;
        }

        const center = mapRecord.viewport?.center;
        const viewCenter = Array.isArray(center) && Math.abs(center[0]) <= 180 ? fromLonLat(center) : center || fromLonLat([0, 20]);
        const map = new Map({
            target: target.current,
            layers: [new TileLayer({ source: basemapSource(mapRecord.basemap) })],
            view: new View({
                center: viewCenter,
                zoom: mapRecord.viewport?.zoom || 2,
                rotation: mapRecord.viewport?.rotation || 0,
            }),
        });

        (mapRecord.layers || []).forEach((layerConfig) => {
            if (layerConfig.visible === false) {
                return;
            }
            const styleCfg = layerConfig.style_config || layerConfig.style || {};
            const vectorStyle = new Style({
                fill: new Fill({ color: styleCfg.fill_color || '#06b6d4' }),
                stroke: new Stroke({ color: styleCfg.stroke_color || '#dae2fd', width: Number(styleCfg.stroke_width || 1) }),
            });
            if (layerConfig.type === 'mvt' || layerConfig.mvtUrl) {
                map.addLayer(
                    new VectorTileLayer({
                        source: new VectorTileSource({
                            format: new MVT(),
                            url: layerConfig.mvtUrl || `/api/layers/${layerConfig.id}/tiles/{z}/{x}/{y}.mvt`,
                        }),
                        style: vectorStyle,
                        opacity: layerConfig.opacity || 1,
                    }),
                );
            } else if (layerConfig.type === 'wms') {
                map.addLayer(
                    new TileLayer({
                        source: attachTileCache(
                            new TileWMS({
                                url: layerConfig.url,
                                params: { LAYERS: layerConfig.layers, TILED: true, ...(layerConfig.wmsParams || {}) },
                            }),
                            'wms',
                        ),
                        opacity: layerConfig.opacity || 1,
                    }),
                );
            } else if (layerConfig.url) {
                map.addLayer(
                    new VectorLayer({
                        source: new VectorSource({ url: layerConfig.url, format: new GeoJSON() }),
                        style: vectorStyle,
                        opacity: layerConfig.opacity || 1,
                    }),
                );
            }
        });

        return () => map.setTarget(null);
    }, [mapRecord]);

    return <div ref={target} className="h-full w-full" />;
}
