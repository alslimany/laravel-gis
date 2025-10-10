# Quick Start Guide - New Features

This guide provides quick instructions for using the newly added features.

## 1. Setting Up MapBox

### Get Your MapBox Token
1. Visit https://account.mapbox.com/access-tokens/
2. Sign up or log in
3. Create a new token or use the default public token
4. Copy the token

### Configure Your Application
Add to your `.env` file:
```env
VITE_MAPBOX_TOKEN=pk.your_mapbox_token_here
```

### Rebuild Frontend Assets
```bash
npm run build
```

### Use MapBox Basemaps
In the Map Builder:
1. Open the Layer Panel (left side)
2. Find the "Base Map" dropdown
3. Select "Satellite (MapBox)" or "MapBox Streets"
4. The map will update automatically

## 2. Using the Enhanced Dashboard

### View GIS Statistics
1. Navigate to `/dashboard` after logging in
2. View overview cards showing:
   - Total Layers (with published count)
   - Total Maps
   - Total Projects
   - Estimated Storage Usage

### Quick Access
- Click "View all" links on statistic cards
- Click on recent layers to view details
- Click on recent maps to open them
- Use "Create Map" button to start building

### Understanding Statistics
- **Published Layers:** Layers available on GeoServer
- **Storage Used:** Estimated based on feature count
- **Recent Items:** Last 5 layers/maps created

## 3. Adding Layers from GeoServer

### Prerequisites
- At least one layer must be published to GeoServer
- You must be in the Map Builder

### Steps to Add a Layer
1. Open Map Builder (`/maps/builder`)
2. Click "Add Layer" button in the Layer Panel
3. In the modal, select "From GeoServer" as Layer Source
4. Wait for the published layers list to load
5. Select a layer from the dropdown
6. Layer details auto-populate (name, URL, layer name)
7. Click "Add Layer"
8. The layer appears on your map

### Adding Custom Layers
1. In the Add Layer modal, select "Custom URL"
2. Choose layer type (WMS, WFS, or Vector)
3. Enter layer name
4. Enter layer URL
5. For WMS, enter layer names (e.g., `workspace:layername`)
6. Click "Add Layer"

## 4. Managing Layers

### Publishing to GeoServer
1. Go to Layers list (`/layers`)
2. Click on a layer to view details
3. Click "Publish to GeoServer" button
4. Wait for confirmation message
5. Layer is now available in Map Builder

### Unpublishing from GeoServer
1. View the published layer details
2. Click "Unpublish from GeoServer" button
3. Confirmation message appears
4. Layer is removed from GeoServer but remains in database

### Deleting a Layer
1. View the layer details
2. Click "Delete" button
3. Confirm deletion
4. Layer is automatically removed from GeoServer if published
5. Layer is deleted from database

## 5. Creating and Saving Maps

### Create a New Map
1. Navigate to `/maps/builder`
2. Add layers using the Layer Panel
3. Select a base map
4. Use drawing tools to add features (optional)
5. Click "Save Map" button
6. Map is saved to your organization

### Understanding Map Configuration
Maps store:
- Viewport (center, zoom, rotation)
- Selected basemap
- All added layers with their configuration
- Layer visibility and opacity settings

## 6. Troubleshooting

### Map Builder Save Returns 404
**Fixed in this release!** If you still see this:
- Verify `bootstrap/app.php` includes API routes
- Clear route cache: `php artisan route:clear`
- Check browser console for specific errors

### MapBox Tiles Not Loading
- Verify your MapBox token is set in `.env`
- Check the token is valid at https://account.mapbox.com/
- Ensure frontend assets are rebuilt: `npm run build`
- Check browser console for authentication errors

### Published Layers Not Showing
- Ensure layers are published to GeoServer
- Check GeoServer is running and accessible
- Verify user has permission to view layers
- Refresh the browser

### Dashboard Statistics Not Accurate
- Statistics are based on database records
- Storage usage is estimated (not exact)
- Refresh the page to update counts

## 7. Best Practices

### Layer Management
- Use descriptive names for layers
- Publish only necessary layers to GeoServer
- Unpublish unused layers to save resources
- Delete old/unused layers regularly

### Map Building
- Start with a suitable base map
- Add layers in order from bottom to top
- Use layer visibility to toggle layers on/off
- Save maps frequently while building

### Organization
- Create projects to group related data
- Use consistent naming conventions
- Document layer purposes in descriptions
- Share maps appropriately (public vs. private)

## 8. Keyboard Shortcuts

In Map Builder:
- `Ctrl/Cmd + S` - Save map (if implemented)
- `Esc` - Close modals
- Mouse wheel - Zoom in/out
- Click + Drag - Pan the map

## 9. API Usage

### Create a Map via API
```javascript
const response = await axios.post('/api/maps', {
  name: 'My Map',
  description: 'Description here',
  viewport: {
    center: [0, 0],
    zoom: 2
  },
  basemap: 'osm',
  layers: [],
  is_public: false
});
```

### Update a Map via API
```javascript
const response = await axios.put(`/api/maps/${mapId}`, {
  name: 'Updated Name',
  layers: [/* updated layers */]
});
```

### List Layers via API
```javascript
const response = await axios.get('/layers', {
  headers: {
    'Accept': 'application/json'
  }
});
```

## 10. Getting Help

### Documentation
- `ENHANCEMENTS_SUMMARY.md` - Detailed feature documentation
- `MAP_BUILDER_DOCUMENTATION.md` - Complete Map Builder guide
- `GEOSERVER_INTEGRATION.md` - GeoServer setup and usage
- `API_DOCUMENTATION.md` - API reference

### Common Issues
1. **Cannot save map:** Check authentication and API route configuration
2. **Basemap not loading:** Verify MapBox token and internet connection
3. **Layers not appearing:** Check layer URL and GeoServer status
4. **Permission denied:** Verify user has proper organization access

### Support Resources
- Check application logs: `storage/logs/laravel.log`
- Browser console for frontend errors
- GeoServer logs for layer issues
- Database queries for data verification

---

**Last Updated:** 2025-10-10
**Version:** Current HEAD

For detailed documentation, see `ENHANCEMENTS_SUMMARY.md` and other documentation files.
