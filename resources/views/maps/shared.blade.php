<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $map->name }} - Shared Map</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body, html {
            height: 100%;
            font-family: Arial, sans-serif;
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
            padding: 10px 15px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            z-index: 100;
        }
        .map-info h3 {
            margin: 0;
            font-size: 18px;
        }
        .map-info p {
            margin: 5px 0 0 0;
            font-size: 14px;
            color: #666;
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
        
        // Create base layer based on basemap config
        let baseSource;
        if (mapData.basemap === 'osm' || !mapData.basemap) {
            baseSource = new ol.source.OSM();
        }
        
        const baseLayer = new ol.layer.Tile({
            source: baseSource
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

                if (layerConfig.type === 'wms') {
                    const wmsLayer = new ol.layer.Tile({
                        source: new ol.source.TileWMS({
                            url: layerConfig.url,
                            params: {
                                'LAYERS': layerConfig.layers,
                                'TILED': true
                            }
                        }),
                        opacity: layerConfig.opacity || 1
                    });
                    map.addLayer(wmsLayer);
                }
            });
        }
    </script>
</body>
</html>
