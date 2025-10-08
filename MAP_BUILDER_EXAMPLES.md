# Map Builder Usage Examples

This document provides practical examples of using the Map Builder feature.

## Table of Contents
1. [Basic Usage](#basic-usage)
2. [Adding Layers](#adding-layers)
3. [Styling Layers](#styling-layers)
4. [Saving Maps](#saving-maps)
5. [Sharing Maps](#sharing-maps)
6. [API Usage](#api-usage)
7. [Integration Examples](#integration-examples)

## Basic Usage

### Creating Your First Map

1. **Navigate to Maps**
   - Log in to the application
   - Click "Maps" in the navigation menu
   - Click "Create New Map" button

2. **Set Up Base Map**
   - The map opens with OpenStreetMap as default
   - Use the Base Map selector to change to Bing Aerial or Bing Road
   - Pan and zoom to your area of interest

3. **Add Your First Layer**
   - Click "Add Layer" in the Layer Panel
   - Fill in the layer details
   - Click "Add Layer" to confirm

4. **Save Your Map**
   - Click "Save Map" in the header
   - Your map is saved to the database

## Adding Layers

### Adding a WMS Layer from GeoServer

**Scenario**: You have published a layer to GeoServer and want to add it to your map.

```
1. Click "Add Layer"
2. Select "WMS Layer" from the type dropdown
3. Enter Layer Name: "County Boundaries"
4. Enter URL: "http://localhost:8080/geoserver/wms"
5. Enter Layer Names: "my_workspace:counties"
6. Click "Add Layer"
```

The layer will appear in the Layer Panel and on the map.

### Adding a Vector Layer (GeoJSON)

**Scenario**: You want to add a GeoJSON layer from your Laravel application.

```
1. Click "Add Layer"
2. Select "Vector Layer (GeoJSON)" from the type dropdown
3. Enter Layer Name: "Points of Interest"
4. Enter URL: "/api/layers/5/geojson" (where 5 is your layer ID)
5. Click "Add Layer"
```

### Adding Multiple Layers

You can add as many layers as needed:

```javascript
// Example layer configuration stored in database:
{
  "layers": [
    {
      "id": "1",
      "name": "Base Layer",
      "type": "wms",
      "url": "http://localhost:8080/geoserver/wms",
      "layers": "workspace:base_layer",
      "visible": true,
      "opacity": 1
    },
    {
      "id": "2",
      "name": "Points",
      "type": "vector",
      "url": "/api/layers/2/geojson",
      "visible": true,
      "opacity": 0.8
    }
  ]
}
```

## Styling Layers

### Styling a Vector Layer

**Scenario**: You want to customize the appearance of a vector layer.

1. **Select the Layer**
   - Click on the layer in the Layer Panel
   - The Style Editor appears on the right

2. **Adjust Colors**
   - Fill Color: Click the color picker, select blue (#3388ff)
   - Stroke Color: Select a darker blue (#0066cc)

3. **Adjust Opacity**
   - Fill Opacity: Slide to 60%
   - Stroke Opacity: Keep at 100%

4. **Set Stroke Width**
   - Enter 3 for stroke width

5. **Apply Changes**
   - Click "Apply Style"
   - Your changes appear immediately on the map

### Styling Example - Water Bodies

```javascript
// Typical water body styling
{
  "fillColor": "#4A90E2",
  "fillOpacity": 0.5,
  "strokeColor": "#2E5C8A",
  "strokeWidth": 2,
  "strokeOpacity": 1
}
```

### Styling Example - Parks

```javascript
// Typical park styling
{
  "fillColor": "#7CB342",
  "fillOpacity": 0.6,
  "strokeColor": "#558B2F",
  "strokeWidth": 1,
  "strokeOpacity": 0.8
}
```

## Saving Maps

### Save New Map

**Via UI:**
1. Click "Save Map" button
2. Map is saved with current configuration
3. Success notification appears
4. Map appears in Maps list

**Via API:**
```javascript
// Using axios in a Vue component
const saveMap = async () => {
  try {
    const response = await axios.post('/api/maps', {
      name: 'My Custom Map',
      description: 'A map showing county boundaries and points of interest',
      viewport: {
        center: [-122.4194, 37.7749],
        zoom: 10,
        rotation: 0
      },
      basemap: 'osm',
      layers: [
        {
          id: '1',
          name: 'Counties',
          type: 'wms',
          url: 'http://localhost:8080/geoserver/wms',
          layers: 'workspace:counties',
          visible: true,
          opacity: 0.7
        }
      ],
      is_public: false
    });
    
    console.log('Map saved:', response.data);
  } catch (error) {
    console.error('Error saving map:', error);
  }
};
```

### Update Existing Map

**Via API:**
```javascript
// Update a map
const updateMap = async (mapId) => {
  try {
    const response = await axios.put(`/api/maps/${mapId}`, {
      name: 'Updated Map Name',
      viewport: mapStore.viewport,
      layers: mapStore.layers
    });
    
    console.log('Map updated:', response.data);
  } catch (error) {
    console.error('Error updating map:', error);
  }
};
```

### Export Map Configuration

```javascript
// Export map as JSON file
const exportMap = () => {
  const mapData = {
    viewport: mapStore.viewport,
    basemap: mapStore.basemap,
    layers: mapStore.layers
  };
  
  const blob = new Blob([JSON.stringify(mapData, null, 2)], { 
    type: 'application/json' 
  });
  
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'map-config.json';
  a.click();
  URL.revokeObjectURL(url);
};
```

## Sharing Maps

### Make a Map Public

1. **Go to Share Settings**
   - View your map
   - Click "Share" button

2. **Enable Public Access**
   - Toggle "Make this map public" to ON
   - A share link is generated automatically

3. **Copy Share Link**
   - Click "Copy" next to the share URL
   - Example: `https://yourdomain.com/maps/shared/abc123xyz789`

4. **Get Embed Code**
   - Copy the iframe embed code
   - Paste into your website or blog

### Embed Example

```html
<!-- Embed a shared map in your website -->
<iframe 
  src="https://yourdomain.com/maps/shared/abc123xyz789" 
  width="100%" 
  height="600" 
  frameborder="0"
  style="border: 1px solid #ccc;"
>
</iframe>
```

### Programmatically Share a Map

```php
// In a Laravel controller
use App\Models\Map;

public function shareMap($id)
{
    $map = Map::findOrFail($id);
    
    // Make map public
    $map->update(['is_public' => true]);
    
    // Get share URL
    $shareUrl = route('maps.shared', $map->share_token);
    
    return response()->json([
        'share_url' => $shareUrl,
        'embed_code' => '<iframe src="' . $shareUrl . '" width="100%" height="600"></iframe>'
    ]);
}
```

## API Usage

### List All Maps

```javascript
// GET /api/maps
const listMaps = async () => {
  try {
    const response = await axios.get('/api/maps');
    console.log('Maps:', response.data);
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### Get Single Map

```javascript
// GET /api/maps/:id
const getMap = async (mapId) => {
  try {
    const response = await axios.get(`/api/maps/${mapId}`);
    console.log('Map:', response.data);
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### Create Map

```javascript
// POST /api/maps
const createMap = async () => {
  try {
    const response = await axios.post('/api/maps', {
      name: 'New Map',
      description: 'Map description',
      viewport: {
        center: [0, 0],
        zoom: 2
      },
      basemap: 'osm',
      layers: [],
      is_public: false
    });
    
    console.log('Created:', response.data);
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### Update Map

```javascript
// PUT /api/maps/:id
const updateMap = async (mapId) => {
  try {
    const response = await axios.put(`/api/maps/${mapId}`, {
      name: 'Updated Name',
      layers: [
        // updated layers
      ]
    });
    
    console.log('Updated:', response.data);
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### Delete Map

```javascript
// DELETE /api/maps/:id
const deleteMap = async (mapId) => {
  try {
    await axios.delete(`/api/maps/${mapId}`);
    console.log('Deleted successfully');
  } catch (error) {
    console.error('Error:', error);
  }
};
```

## Integration Examples

### Integrating with GeoServer

**Step 1: Publish a Layer to GeoServer**
```bash
# Using the existing layer management system
php artisan geoserver:publish-layer workspace datastore layer_table
```

**Step 2: Add to Map**
```javascript
// In the map builder
const geoserverLayer = {
  id: Date.now().toString(),
  name: 'Published Layer',
  type: 'wms',
  url: 'http://localhost:8080/geoserver/wms',
  layers: 'workspace:layer_table',
  visible: true,
  opacity: 1
};

mapStore.addLayer(geoserverLayer);
```

### Loading Layers from Database

```php
// In a Laravel controller
use App\Models\Layer;
use App\Models\Map;

public function createMapFromLayers(Request $request)
{
    // Get layers from database
    $layers = Layer::where('organization_id', auth()->user()->organization_id)
        ->where('published', true)
        ->get();
    
    // Convert to map layer format
    $mapLayers = $layers->map(function($layer) {
        return [
            'id' => $layer->id,
            'name' => $layer->name,
            'type' => 'wms',
            'url' => config('geoserver.url') . '/wms',
            'layers' => config('geoserver.workspace') . ':' . $layer->table_name,
            'visible' => true,
            'opacity' => 1
        ];
    });
    
    // Create map
    $map = Map::create([
        'name' => 'Auto-generated Map',
        'user_id' => auth()->id(),
        'organization_id' => auth()->user()->organization_id,
        'viewport' => [
            'center' => [0, 0],
            'zoom' => 2
        ],
        'basemap' => 'osm',
        'layers' => $mapLayers
    ]);
    
    return response()->json($map);
}
```

### Custom Layer Panel Component

```vue
<!-- Example: Custom layer management -->
<template>
  <div class="custom-layer-panel">
    <h4>My Layers</h4>
    <div v-for="layer in publishedLayers" :key="layer.id">
      <button @click="addToMap(layer)">
        Add {{ layer.name }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useMapStore } from '@/stores/mapStore';
import axios from 'axios';

const mapStore = useMapStore();
const publishedLayers = ref([]);

onMounted(async () => {
  // Load published layers
  const response = await axios.get('/api/layers?published=true');
  publishedLayers.value = response.data;
});

const addToMap = (layer) => {
  const mapLayer = {
    id: `layer-${layer.id}`,
    name: layer.name,
    type: 'wms',
    url: 'http://localhost:8080/geoserver/wms',
    layers: `workspace:${layer.table_name}`,
    visible: true,
    opacity: 1
  };
  
  mapStore.addLayer(mapLayer);
};
</script>
```

### Batch Operations

```javascript
// Add multiple layers at once
const addMultipleLayers = async (layerIds) => {
  for (const layerId of layerIds) {
    const response = await axios.get(`/api/layers/${layerId}`);
    const layer = response.data;
    
    mapStore.addLayer({
      id: `layer-${layer.id}`,
      name: layer.name,
      type: 'wms',
      url: 'http://localhost:8080/geoserver/wms',
      layers: `workspace:${layer.table_name}`,
      visible: true,
      opacity: 0.8
    });
  }
};

// Usage
addMultipleLayers([1, 2, 3, 4, 5]);
```

### Working with Feature Information

```javascript
// Handle feature selection in MapComponent
selectInteraction.on('select', (event) => {
  if (event.selected.length > 0) {
    const feature = event.selected[0];
    const properties = feature.getProperties();
    
    // Format properties for display
    let content = '<div class="feature-info">';
    content += '<h5>Feature Information</h5>';
    
    for (let key in properties) {
      if (key !== 'geometry') {
        content += `<p><strong>${key}:</strong> ${properties[key]}</p>`;
      }
    }
    
    content += '</div>';
    
    // Show in popup
    popup.innerHTML = content;
    popupOverlay.setPosition(event.mapBrowserEvent.coordinate);
  }
});
```

## Advanced Examples

### Dynamic Layer Loading

```javascript
// Load layers based on zoom level
watch(() => mapStore.viewport.zoom, (newZoom) => {
  if (newZoom > 15) {
    // Add detailed layers at high zoom
    loadDetailedLayers();
  } else {
    // Remove detailed layers at low zoom
    removeDetailedLayers();
  }
});

const loadDetailedLayers = () => {
  // Implementation
};

const removeDetailedLayers = () => {
  // Implementation
};
```

### Custom Base Map

```javascript
// Add custom tile layer as base map
const customBasemap = new TileLayer({
  source: new XYZ({
    url: 'https://your-tile-server.com/{z}/{x}/{y}.png',
    attributions: '© Your Company'
  })
});

// Add to store
mapStore.availableBasemaps.push({
  id: 'custom',
  name: 'Custom Basemap',
  type: 'tile'
});
```

### Programmatic Map Control

```javascript
// Fly to a location
const flyTo = (coordinate, zoom = 15) => {
  const view = mapStore.map.getView();
  view.animate({
    center: fromLonLat(coordinate),
    zoom: zoom,
    duration: 2000
  });
};

// Usage
flyTo([-122.4194, 37.7749], 12); // Fly to San Francisco
```

These examples should help you get started with the Map Builder feature and integrate it into your workflows!
