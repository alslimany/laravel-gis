# Map Builder Feature - README

## 🎉 Welcome to the Map Builder!

The Map Builder is an OpenLayers map workspace in the Laravel GIS application. Users create, manage, share, and embed maps. The UI is React 19 and Inertia (`resources/js/Pages/Maps/Builder.tsx` and `resources/js/map-workspace/`). Stack versions are in `composer.json` and `package.json`.

## Documentation

Current guides:

1. **MAP_BUILDER_QUICK_REFERENCE.md** ⚡
   - Quick access guide
   - Common tasks
   - API endpoints
   - Code snippets
   - **Start here for quick answers!**

2. **MAP_BUILDER_DOCUMENTATION.md** 📖
   - Comprehensive user guide
   - Feature overview
   - Usage instructions
   - Configuration options
   - Troubleshooting

`MAP_BUILDER_IMPLEMENTATION_SUMMARY.md`, `MAP_BUILDER_ARCHITECTURE.md`, `MAP_BUILDER_EXAMPLES.md`, `MAP_BUILDER_CHECKLIST.md`, `MAP_BUILDER_FINAL_SUMMARY.md`, and `MAP_BUILDER_FIXES.md` are historical Vue 3 snapshots. Each file is labeled at the top. The getting-started path is this file, the quick reference, and the documentation guide.

## 🚀 Quick Start

### For End Users

1. **Create Your First Map**
   ```
   1. Navigate to Maps → Create New Map
   2. Add layers using the Layer Panel
   3. Style layers using the Style Editor
   4. Save your map
   ```

2. **Share a Map**
   ```
   1. View your map
   2. Click "Share"
   3. Toggle "Make this map public"
   4. Copy the share link
   ```

### For Developers

The builder is a React page. `MapController::builder` returns `Inertia::render('Maps/Builder')`, which loads `resources/js/Pages/Maps/Builder.tsx`. That page mounts `MapWorkspace` from `resources/js/map-workspace/`.

1. **Install dependencies**
   ```bash
   composer install
   npm install
   ```

2. **Build assets**
   ```bash
   npm run build
   ```
   Vite compiles `resources/js/app.tsx` (Inertia) and `resources/js/map-workspace/main.jsx`.

3. **Run migrations**
   ```bash
   php artisan migrate
   ```

4. **Open the builder**
   ```
   Navigate to: /maps/builder
   ```

## 🎯 Key Features

### Map Building
- ✅ Interactive OpenLayers map
- ✅ Base maps: Street (OpenStreetMap) and Satellite (`mapStore.js`)
- ✅ WMS/WFS/Vector layer support
- ✅ Drag-and-drop layer ordering
- ✅ Layer visibility toggle
- ✅ Real-time map preview

### Styling
- ✅ Layer opacity control
- ✅ Fill and stroke colors
- ✅ Stroke width adjustment
- ✅ Style presets
- ✅ Live preview

### Management
- ✅ Save/load maps
- ✅ Export configuration
- ✅ Edit existing maps
- ✅ Delete maps
- ✅ Organization scoping

### Sharing
- ✅ Public/private toggle
- ✅ Unique share links
- ✅ Embed codes
- ✅ Token-based access
- ✅ Standalone viewer

### Integration
- ✅ GeoServer WMS/WFS
- ✅ Layer management system
- ✅ Authentication
- ✅ Authorization
- ✅ RESTful API

## Where the code lives

- **Inertia pages**: `resources/js/Pages/Maps/` (`Builder.tsx`, `Index.tsx`, `Show.tsx`, `Share.tsx`, `Form.tsx`, `Shared.tsx`)
- **Workspace**: `resources/js/map-workspace/MapWorkspace.jsx`
- **Map**: `resources/js/map-workspace/map/MapView.jsx` (OpenLayers)
- **Panels**: `resources/js/map-workspace/panels/` (`LayerPanel.jsx`, `ToolPanel.jsx`, `StyleEditor.jsx`, `AnalysisPanel.jsx`)
- **State**: Zustand store `resources/js/map-workspace/store/mapStore.js` (`useMapStore`)
- **Controller**: `app/Http/Controllers/MapController.php`
- **Model**: `app/Models/Map.php`

## Architecture

```
Inertia page (React) — resources/js/Pages/Maps/Builder.tsx
    ↓
MapWorkspace — resources/js/map-workspace/MapWorkspace.jsx
    ↓
Zustand (useMapStore) and OpenLayers (ol)
    ↓
Laravel (MapController) and PostgreSQL / PostGIS
    ↓
GeoServer (optional WMS/WFS)
```

## Technology stack

Versions are declared in `composer.json` and `package.json`.

| Layer | Technology |
|-------|------------|
| **Frontend** | React 19, `@inertiajs/react`, OpenLayers (`ol` ^10) |
| **Build** | Vite, `@vitejs/plugin-react` |
| **Backend** | Laravel 13 (`laravel/framework` ^13.0), PHP ^8.3 |
| **Server adapter** | `inertiajs/inertia-laravel` |
| **Database** | PostgreSQL with PostGIS |
| **State** | Zustand |
| **Layer order** | `@dnd-kit` in `LayerPanel.jsx` |

## 🔐 Security

- ✅ Authentication required for all operations
- ✅ Organization-based data isolation
- ✅ Authorization checks per operation
- ✅ Token-based public sharing
- ✅ CSRF protection
- ✅ SQL injection prevention

## 🎓 Learning Path

**Getting started** → This file, then [MAP_BUILDER_QUICK_REFERENCE.md](MAP_BUILDER_QUICK_REFERENCE.md)

**Usage** → [MAP_BUILDER_DOCUMENTATION.md](MAP_BUILDER_DOCUMENTATION.md)

**Code** → `resources/js/Pages/Maps/Builder.tsx` and `resources/js/map-workspace/`

`MAP_BUILDER_ARCHITECTURE.md`, `MAP_BUILDER_IMPLEMENTATION_SUMMARY.md`, `MAP_BUILDER_EXAMPLES.md`, `MAP_BUILDER_CHECKLIST.md`, `MAP_BUILDER_FINAL_SUMMARY.md`, and `MAP_BUILDER_FIXES.md` are historical Vue 3 snapshots. They are not the current builder.

## 📞 Support

### Documentation
- Check the appropriate documentation file for your need
- All docs are in the project root directory

### Troubleshooting
1. Check Laravel logs: `storage/logs/laravel.log`
2. Check browser console (F12)
3. Verify assets are built: `npm run build`
4. Check database connection
5. Verify authentication

### Common Issues

**Map doesn't load?**
- Run `npm run build`
- Clear browser cache
- Check console for errors

**Can't add layers?**
- Verify GeoServer is running
- Check CORS settings
- Test layer URL directly

**Can't save map?**
- Verify authentication
- Check organization membership
- Review Laravel logs

## 🎯 Use Cases

### Urban Planning
Create maps showing zoning, infrastructure, and development plans.

### Environmental Monitoring
Visualize environmental data, habitats, and protected areas.

### Public Services
Map public facilities, services, and accessibility information.

### Research & Analysis
Combine multiple data layers for spatial analysis and visualization.

### Public Engagement
Share maps with stakeholders and the public for feedback.

## 🚦 Getting Started Workflow

```
1. Data Import
   └─> Import spatial data (Shapefile, GeoJSON, etc.)

2. Layer Management
   └─> Create and style layers
   └─> Publish to GeoServer

3. Map Builder
   └─> Create map
   └─> Add layers
   └─> Customize appearance
   └─> Save map

4. Sharing
   └─> Make map public
   └─> Generate share link
   └─> Distribute to stakeholders
```

## 📝 License

This feature is part of the Laravel GIS project and follows the same license terms.

## 🙏 Acknowledgments

- OpenLayers team for the mapping library
- React and Inertia teams for the UI stack
- Laravel team for the backend framework

## 📈 Future Enhancements

Potential improvements for future development:

- 🎨 Advanced drawing and editing tools
- 📊 Charts and data visualization
- 🕐 Time-series animation
- 📱 Mobile-optimized interface
- 🌐 Multi-language support
- 🤝 Real-time collaboration
- 📄 Print and PDF export
- 🔍 Advanced search and filtering
- 🎬 3D visualization with Cesium
- 📊 Analytics and usage tracking

## ✅ Next Steps

1. **Read the Quick Reference** - Get up to speed fast
2. **Try Creating a Map** - Hands-on learning
3. **Explore the Examples** - See practical use cases
4. **Review the Architecture** - Understand the system
5. **Start Building!** - Create amazing maps!

---

**Version**: 1.0.0  
**Date**: 2025-10-08  
**Status**: Production Ready ✅

For detailed information, please refer to the specific documentation files listed above.

**Happy Mapping! 🗺️**
