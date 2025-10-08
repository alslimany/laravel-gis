# Map Builder - Quick Reference

## Quick Access

| Action | URL/Path |
|--------|----------|
| **View All Maps** | `/maps` |
| **Create New Map** | `/maps/builder` |
| **Edit Existing Map** | `/maps/builder/{id}` |
| **View Map** | `/maps/{id}` |
| **Share Settings** | `/maps/{id}/share` |
| **Public Shared Map** | `/maps/shared/{token}` |

## Key Components

### Vue Components
```
MapBuilder.vue      - Main container
├── MapComponent.vue    - OpenLayers map
├── LayerPanel.vue      - Layer management
├── ToolPanel.vue       - Map tools
└── StyleEditor.vue     - Style controls
```

### State Management
```javascript
// Access the store
import { useMapStore } from '@/stores/mapStore';
const mapStore = useMapStore();

// Common operations
mapStore.addLayer(layer);
mapStore.removeLayer(id);
mapStore.toggleLayerVisibility(id);
mapStore.updateLayerStyle(id, style);
```

## Common Tasks

### Add a WMS Layer
```
1. Click "Add Layer"
2. Type: WMS Layer
3. Name: Layer name
4. URL: http://localhost:8080/geoserver/wms
5. Layers: workspace:layername
6. Click "Add Layer"
```

### Style a Layer
```
1. Click layer in Layer Panel
2. Adjust colors and opacity in Style Editor
3. Click "Apply Style"
```

### Share a Map
```
1. View map
2. Click "Share"
3. Toggle "Make this map public"
4. Copy share link
```

## API Endpoints

### REST API
```javascript
// Create map
POST /api/maps
Body: { name, description, viewport, basemap, layers }

// Update map
PUT /api/maps/{id}
Body: { name, viewport, layers }

// Get map
GET /api/maps/{id}

// Delete map
DELETE /api/maps/{id}
```

## Layer Configuration Format

```javascript
{
  "id": "unique-id",
  "name": "Layer Name",
  "type": "wms|wfs|vector",
  "url": "http://service-url",
  "layers": "workspace:layer",  // WMS only
  "visible": true,
  "opacity": 1.0,
  "style": {
    "fillColor": "#3388ff",
    "fillOpacity": 0.6,
    "strokeColor": "#3388ff",
    "strokeWidth": 2,
    "strokeOpacity": 1.0
  }
}
```

## Map Configuration Format

```javascript
{
  "name": "Map Name",
  "description": "Description",
  "viewport": {
    "center": [-122.4194, 37.7749],
    "zoom": 10,
    "rotation": 0
  },
  "basemap": "osm",
  "layers": [...],
  "is_public": false
}
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| **Ctrl/Cmd + S** | Save map (if implemented) |
| **+** | Zoom in |
| **-** | Zoom out |
| **Arrow Keys** | Pan map |
| **Shift + Drag** | Zoom to box |

## Basemap Options

| ID | Name | Description |
|----|------|-------------|
| `osm` | OpenStreetMap | Default free basemap |
| `bing-aerial` | Bing Aerial | Satellite imagery (key required) |
| `bing-road` | Bing Road | Road map (key required) |

## Layer Types

### WMS (Web Map Service)
- Used for published GeoServer layers
- Tiled for performance
- Supports GetFeatureInfo

### WFS (Web Feature Service)
- Vector features from GeoServer
- Supports filtering and queries
- Can be styled client-side

### Vector (GeoJSON)
- Direct GeoJSON data
- Full styling control
- Best for small datasets

## Database Schema

```sql
-- maps table
CREATE TABLE maps (
  id BIGINT PRIMARY KEY,
  name VARCHAR(255),
  description TEXT,
  user_id BIGINT,
  organization_id BIGINT,
  viewport JSON,
  basemap VARCHAR(255),
  layers JSON,
  is_public BOOLEAN,
  share_token VARCHAR(255),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

## Security

### Authorization Rules
- Maps are organization-scoped
- Only authenticated users can create/edit
- Public maps accessible via share token
- Private maps require authentication

### Sharing
```
Private Map → Only org members can view
Public Map → Anyone with link can view
Share Token → Unique, randomly generated
```

## Troubleshooting

### Map Doesn't Load
```bash
# Check assets are built
npm run build

# Clear cache
php artisan cache:clear
```

### Layers Don't Appear
```
1. Check layer URL is accessible
2. Verify CORS settings
3. Check browser console for errors
4. Test URL directly in browser
```

### Can't Save Map
```
1. Check authentication
2. Verify organization membership
3. Check Laravel logs
4. Verify database connection
```

## File Locations

```
Frontend:
  resources/js/map-builder.js
  resources/js/stores/mapStore.js
  resources/js/components/map-builder/

Backend:
  app/Models/Map.php
  app/Http/Controllers/MapController.php
  database/migrations/*_create_maps_table.php

Views:
  resources/views/maps/

Routes:
  routes/web.php
  routes/api.php

Docs:
  MAP_BUILDER_DOCUMENTATION.md
  MAP_BUILDER_IMPLEMENTATION_SUMMARY.md
  MAP_BUILDER_ARCHITECTURE.md
  MAP_BUILDER_EXAMPLES.md
```

## Common Code Snippets

### Add Layer Programmatically
```javascript
const layer = {
  id: Date.now().toString(),
  name: 'My Layer',
  type: 'wms',
  url: 'http://localhost:8080/geoserver/wms',
  layers: 'workspace:layer',
  visible: true,
  opacity: 1
};
mapStore.addLayer(layer);
```

### Save Map
```javascript
const saveMap = async () => {
  const response = await axios.post('/api/maps', {
    name: 'My Map',
    viewport: mapStore.viewport,
    basemap: mapStore.basemap,
    layers: mapStore.layers
  });
};
```

### Change Basemap
```javascript
mapStore.setBasemap('bing-aerial');
```

### Fly to Location
```javascript
const view = mapStore.map.getView();
view.animate({
  center: fromLonLat([-122.4194, 37.7749]),
  zoom: 12,
  duration: 2000
});
```

## Integration Points

### With GeoServer
```
Layer Management → Publish to GeoServer → Add to Map
```

### With Layer System
```
Data Import → Create Layer → Publish → Add to Map
```

### With Organizations
```
User → Organization → Maps (scoped)
```

## Performance Tips

1. Use WMS for large datasets
2. Limit visible layers (3-5 recommended)
3. Use appropriate zoom levels
4. Enable layer caching
5. Optimize vector data size

## Support Resources

- **Documentation**: See `MAP_BUILDER_DOCUMENTATION.md`
- **Examples**: See `MAP_BUILDER_EXAMPLES.md`
- **Architecture**: See `MAP_BUILDER_ARCHITECTURE.md`
- **Laravel Logs**: `storage/logs/laravel.log`
- **Browser Console**: Press F12

## Version Info

- **OpenLayers**: 9.x
- **Vue**: 3.x
- **Pinia**: 2.x
- **Laravel**: 12.x
- **Built**: 2025-10-08

---

For detailed information, see the full documentation files included with this feature.
