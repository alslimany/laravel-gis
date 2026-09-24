# Map Builder Documentation

## Overview

The Map Builder is a comprehensive OpenLayers-based interactive map creation tool integrated into the Laravel GIS application. It allows users to create, manage, and share custom maps with multiple layers, styling options, and GeoServer integration.

## Features

### 1. Interactive Map Builder
- **OpenLayers Integration**: Full-featured OpenLayers map with zoom, pan, and interaction controls
- **Base Map Selection**: Street (OpenStreetMap) and Satellite, from `resources/js/map-workspace/store/mapStore.js`
- **Layer Management**: Add, remove, and reorder layers with drag-and-drop functionality
- **Real-time Preview**: See changes immediately as you build your map

### 2. Layer Types Supported
- **WMS Layers**: Connect to WMS services (including GeoServer)
- **WFS Layers**: Load WFS features for vector display
- **Vector Layers**: Load GeoJSON data directly

### 3. Layer Panel Features
- Layer visibility toggle
- Drag-and-drop layer reordering
- Add layers modal with configuration options
- Delete layers with confirmation
- Base map switcher

### 4. Style Editor
- **Opacity Control**: Adjust layer transparency
- **Vector Styling** (for vector layers):
  - Fill color picker
  - Fill opacity
  - Stroke color picker
  - Stroke width
  - Stroke opacity
- Real-time style preview
- Reset to default styles

### 5. Tool Panel
Map interaction tools including:
- Pan
- Select features
- Measure distance
- Measure area
- Zoom in/out
- Zoom to extent

### 6. Map Management
- **Create Maps**: Build new maps from scratch
- **Save Maps**: Persist map configuration to database
- **Edit Maps**: Load and modify existing maps
- **Export Maps**: Download map configuration as JSON
- **Share Maps**: Generate public share links for maps
- **Embed Maps**: Get embed codes for iframe integration

### 7. Map Sharing
- **Public/Private Toggle**: Control map visibility
- **Share Links**: Generate unique URLs for public maps
- **Embed Codes**: HTML iframe code for embedding maps
- **Token-based Access**: Secure sharing with unique tokens

## Architecture

### Frontend

The builder is React with Inertia. `app/Http/Controllers/MapController.php` renders Inertia pages under `resources/js/Pages/Maps/`. `Builder.tsx` hydrates the Zustand store and mounts `MapWorkspace`.

#### React components (`resources/js/map-workspace/`)
1. **MapWorkspace.jsx**: Workspace container (`mode` is `edit` or `view`)
2. **map/MapView.jsx**: OpenLayers map
3. **panels/LayerPanel.jsx**: Layer list, visibility, and `@dnd-kit` reordering
4. **panels/ToolPanel.jsx**: Map interaction tools
5. **panels/StyleEditor.jsx**: Layer styling controls
6. **panels/AnalysisPanel.jsx**: Spatial and attribute analysis

#### State management
- **Zustand** (`resources/js/map-workspace/store/mapStore.js`, `useMapStore`):
  - Map instance
  - Layers array
  - Selected layer
  - Viewport (center, zoom, rotation)
  - Base map selection
  - Active tool

### Backend Components

#### Models
- **Map Model**: Stores map configurations
  - Name and description
  - User and organization associations
  - Viewport settings (JSON)
  - Base map type
  - Layers configuration (JSON)
  - Public/private status
  - Share token for public access

#### Controllers
- **MapController**: Handles all map operations
  - CRUD operations
  - Map builder view
  - Sharing functionality
  - Public map viewing

#### Database
- **maps table**: Stores map data with JSON fields for flexible configuration

## Usage Guide

### Creating a New Map

1. Navigate to Maps → Create New Map
2. The map builder interface will open
3. Add layers using the "Add Layer" button in the Layer Panel
4. Configure each layer:
   - Choose layer type (WMS, WFS, Vector)
   - Enter layer name
   - Provide layer URL
   - For WMS: specify layer names
5. Adjust layer styling using the Style Editor
6. Save the map using "Save Map" button

### Editing an Existing Map

1. Go to Maps → Maps List
2. Click the "Edit" button on any map
3. The map builder opens with the saved configuration
4. Make your changes
5. Save the updated map

### Sharing a Map

1. View a map from the Maps List
2. Click the "Share" button
3. Toggle "Make this map public" to enable sharing
4. Copy the generated share link
5. Optionally, copy the embed code for iframe integration

### Adding GeoServer Layers

To add layers from your GeoServer instance:

1. In the map builder, click "Add Layer"
2. Select "WMS Layer" as the type
3. Enter your GeoServer WMS URL (e.g., `http://localhost:8080/geoserver/wms`)
4. Enter the layer name in format `workspace:layername`
5. Click "Add Layer"

## API Endpoints

### Web Routes
- `GET /maps` - List all maps
- `GET /maps/builder/{id?}` - Map builder interface
- `GET /maps/{map}` - View a specific map
- `GET /maps/{map}/share` - Share settings
- `GET /maps/shared/{token}` - Public shared map view
- `POST /maps` - Create a new map
- `PUT /maps/{map}` - Update a map
- `DELETE /maps/{map}` - Delete a map

### API Routes
- `POST /api/maps` - Create map (JSON API)
- `PUT /api/maps/{map}` - Update map (JSON API)

## Configuration

### Base maps

Basemaps are declared in `resources/js/map-workspace/store/mapStore.js` and drawn by `map/MapView.jsx`:

```javascript
const availableBasemaps = [
    { id: 'osm', name: 'Street', type: 'tile' },
    { id: 'imagery', name: 'Satellite', type: 'tile' },
];
```

The current builder does not use a Bing Maps key.

## Integration with Existing Features

### GeoServer Integration
The map builder seamlessly integrates with published GeoServer layers:

1. Publish layers to GeoServer using the Layer Management system
2. Add published layers to maps using WMS configuration
3. Layers automatically display with configured styles

### Layer Management System
The map builder complements the existing layer management:

- Create and style layers in the Layer Management system
- Publish to GeoServer
- Add to maps via the Map Builder
- Share maps with stakeholders

## Technical Details

### Dependencies

`composer.json` and `package.json` are the source of truth.

- **OpenLayers** (`ol` ^10)
- **React** ^19 and `@inertiajs/react`
- **Laravel** 13 (`laravel/framework` ^13.0) and `inertiajs/inertia-laravel` on PHP ^8.3
- **Zustand**: map workspace state
- **@dnd-kit**: drag-and-drop layer ordering in `LayerPanel.jsx`
- **Vite** with `@vitejs/plugin-react`

### Browser Support
- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

### Performance Considerations
- Large layers may require optimization
- Consider using vector tiles for large datasets
- WMS layers are tiled for better performance
- Map state is persisted to reduce loading times

## Security

### Authorization
- Maps are scoped to organizations
- Only authenticated users can create maps
- Users can only edit maps in their organization
- Public maps are accessible via share token

### Data Privacy
- Private maps are only accessible to organization members
- Share tokens are randomly generated and unique
- Public status can be toggled at any time

## Troubleshooting

### Map Doesn't Load
- Check browser console for errors
- Verify all dependencies are installed (`npm install`)
- Rebuild assets (`npm run build`)
- Clear browser cache

### Layers Don't Display
- Verify layer URL is accessible
- Check CORS settings on WMS/WFS server
- Ensure layer names are correct
- Test layer URL directly in browser

### GeoServer Connection Issues
- Verify GeoServer is running
- Check GeoServer URL configuration
- Ensure workspace and layers exist
- Review CORS settings in GeoServer

### Save/Update Failures
- Check Laravel logs for errors
- Verify database connection
- Ensure user has proper permissions
- Check organization membership

## Future Enhancements

Potential improvements for future development:

1. **Advanced Drawing Tools**: Add polygon, line, and point drawing
2. **Feature Editing**: Edit vector features directly on the map
3. **Layer Groups**: Organize layers into collapsible groups
4. **Print/Export**: Generate PDF maps or high-resolution images
5. **Measurement Tools**: Enhanced distance and area measurement
6. **Search Functionality**: Search for features by attributes
7. **Time Series**: Support for temporal data visualization
8. **3D Visualization**: Integrate Cesium for 3D maps
9. **Mobile Optimization**: Responsive design for mobile devices
10. **Collaboration**: Real-time multi-user map editing

## Support

For issues or questions:
- Review this documentation
- Check the main README.md
- Review Laravel logs
- Check browser console for JavaScript errors
- Verify GeoServer integration documentation

## License

This Map Builder is part of the Laravel GIS project and follows the same license terms.
