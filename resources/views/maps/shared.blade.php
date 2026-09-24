<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $map->name }} - Shared Map</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600" rel="stylesheet" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body, html {
            height: 100%;
            font-family: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif;
            color: #0b1220;
        }
        #map {
            width: 100%;
            height: 100%;
        }
        .map-info {
            position: absolute;
            top: 10px;
            left: 50px;
            background: white;
            padding: 10px 14px;
            border-radius: 0.65rem;
            border: 1px solid #dce3ec;
            box-shadow: 0 10px 24px -16px rgba(11, 18, 32, 0.45);
            z-index: 100;
        }
        .map-info h3 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
        }
        .map-info p {
            margin: 4px 0 0 0;
            font-size: 0.8rem;
            color: #3f4c5e;
        }
    </style>
</head>
<body>
    <div class="map-info">
        <h3>{{ $map->name }}</h3>
        @if($map->description)
            <p>{{ $map->description }}</p>
        @endif
    </div>
    <div id="map"></div>

    <script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>
    <script>
        const mapData = @json($map);
        
        function basemapSource(id) {
            if (id === 'imagery') {
                return new ol.source.XYZ({
                    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                    maxZoom: 19,
                    attributions: 'Tiles © Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community'
                });
            }
            if (id === 'dark') {
                return new ol.source.XYZ({ url: 'https://{a-c}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', maxZoom: 19 });
            }
            if (id === 'light') {
                return new ol.source.XYZ({ url: 'https://{a-c}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', maxZoom: 19 });
            }
            if (id === 'terrain') {
                return new ol.source.XYZ({ url: 'https://{a-c}.tile.opentopomap.org/{z}/{x}/{y}.png', maxZoom: 17 });
            }
            return new ol.source.OSM();
        }

        const baseLayer = new ol.layer.Tile({
            source: basemapSource(mapData.basemap)
        });

        // Initialize map
        const map = new ol.Map({
            target: 'map',
            layers: [baseLayer],
            view: new ol.View({
                center: ol.proj.fromLonLat(mapData.viewport?.center || [0, 0]),
                zoom: mapData.viewport?.zoom || 2,
                rotation: mapData.viewport?.rotation || 0
            })
        });

        // Add layers if any
        if (mapData.layers && Array.isArray(mapData.layers)) {
            mapData.layers.forEach(layerConfig => {
                if (!layerConfig.visible) return;

                const opacity = layerConfig.opacity || 1;
                const styleCfg = layerConfig.style_config || layerConfig.style || {};
                const fill = styleCfg.fill_color || '#3388ff';
                const stroke = styleCfg.stroke_color || '#000000';
                const vectorStyle = new ol.style.Style({
                    fill: new ol.style.Fill({ color: fill }),
                    stroke: new ol.style.Stroke({
                        color: stroke,
                        width: Number(styleCfg.stroke_width || 1)
                    })
                });

                if ((layerConfig.type === 'mvt' || layerConfig.mvtUrl) && (layerConfig.mvtUrl || layerConfig.id)) {
                    map.addLayer(new ol.layer.VectorTile({
                        source: new ol.source.VectorTile({
                            format: new ol.format.MVT(),
                            url: layerConfig.mvtUrl || `/api/layers/${layerConfig.id}/tiles/{z}/{x}/{y}.mvt`
                        }),
                        style: vectorStyle,
                        opacity: opacity
                    }));
                } else if (layerConfig.type === 'wms') {
                    map.addLayer(new ol.layer.Tile({
                        source: new ol.source.TileWMS({
                            url: layerConfig.url,
                            params: Object.assign({
                                'LAYERS': layerConfig.layers,
                                'TILED': true
                            }, layerConfig.wmsParams || {})
                        }),
                        opacity: opacity
                    }));
                } else if (layerConfig.type === 'vector' || layerConfig.type === 'geojson' || layerConfig.type === 'wfs') {
                    map.addLayer(new ol.layer.Vector({
                        source: new ol.source.Vector({
                            url: layerConfig.url,
                            format: new ol.format.GeoJSON()
                        }),
                        style: vectorStyle,
                        opacity: opacity
                    }));
                }
            });
        }
    </script>
</body>
</html>
