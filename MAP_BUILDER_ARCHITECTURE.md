# Map Builder Architecture

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                            User Interface (Browser)                      │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ├─── Vue 3 Application (map-builder.js)
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
            ┌───────▼────────┐              ┌──────▼─────────┐
            │  MapBuilder.vue │              │  Pinia Store   │
            │   (Container)   │◄─────────────│  (mapStore.js) │
            └───────┬────────┘              └────────────────┘
                    │
        ┌───────────┼───────────┬───────────────┐
        │           │           │               │
   ┌────▼────┐ ┌───▼────┐ ┌────▼─────┐  ┌─────▼──────┐
   │MapComp. │ │Layer   │ │Tool      │  │Style       │
   │  .vue   │ │Panel   │ │Panel     │  │Editor      │
   │         │ │.vue    │ │.vue      │  │.vue        │
   └────┬────┘ └───┬────┘ └────┬─────┘  └─────┬──────┘
        │          │           │              │
        └──────────┴───────────┴──────────────┘
                        │
                        │ API Calls (axios)
                        │
┌───────────────────────▼─────────────────────────────────────────────────┐
│                         Laravel Backend                                  │
│  ┌──────────────┐  ┌─────────────────┐  ┌──────────────────────────┐  │
│  │  MapController│◄─┤  Routes         │  │  Map Model               │  │
│  │               │  │  - web.php      │  │  - name, description     │  │
│  │  - index()    │  │  - api.php      │  │  - viewport (JSON)       │  │
│  │  - create()   │  │                 │  │  - layers (JSON)         │  │
│  │  - store()    │  └─────────────────┘  │  - basemap               │  │
│  │  - show()     │                        │  - is_public             │  │
│  │  - update()   │  ┌─────────────────┐  │  - share_token           │  │
│  │  - destroy()  │◄─┤  Middleware     │  └──────────┬───────────────┘  │
│  │  - builder()  │  │  - auth         │             │                   │
│  │  - share()    │  │  - organization │             │                   │
│  │  - viewShared │  └─────────────────┘             │                   │
│  └───────┬───────┘                                  │                   │
│          │                                           │                   │
│          └───────────────────────────────────────────┘                   │
└───────────────────────────┬─────────────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────────────┐
│                        PostgreSQL Database                               │
│                                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  maps                                                            │   │
│  ├──────────────┬──────────────┬──────────────┬────────────────────┤   │
│  │ id           │ name         │ description  │ user_id            │   │
│  │ organization │ viewport     │ basemap      │ layers (JSON)      │   │
│  │ is_public    │ share_token  │ created_at   │ updated_at         │   │
│  └──────────────┴──────────────┴──────────────┴────────────────────┘   │
│                                                                           │
│  (Related tables: users, organizations)                                  │
└───────────────────────────────────────────────────────────────────────────┘
```

## Component Relationships

```
┌────────────────────────────────────────────────────────────────────┐
│                         MapBuilder.vue                              │
│                    (Main Container Component)                       │
│                                                                     │
│  ┌──────────────┐  ┌─────────────────────┐  ┌──────────────────┐ │
│  │              │  │                     │  │                  │ │
│  │ LayerPanel   │  │   MapComponent      │  │  StyleEditor     │ │
│  │              │  │                     │  │                  │ │
│  │ - Add Layer  │  │ ┌─────────────────┐ │  │ - Opacity        │ │
│  │ - Remove     │  │ │  OpenLayers Map │ │  │ - Fill Color     │ │
│  │ - Reorder    │  │ │                 │ │  │ - Stroke Color   │ │
│  │ - Visibility │  │ │ - Base Layer    │ │  │ - Width          │ │
│  │ - Basemap    │  │ │ - WMS Layers    │ │  │ - Apply/Reset    │ │
│  │   Selector   │  │ │ - Vector Layers │ │  │                  │ │
│  │              │  │ │ - Controls      │ │  │                  │ │
│  └──────────────┘  │ │ - Interactions  │ │  └──────────────────┘ │
│                    │ └─────────────────┘ │                        │
│                    │                     │                        │
│                    │ ┌─────────────────┐ │                        │
│                    │ │   ToolPanel     │ │                        │
│                    │ │                 │ │                        │
│                    │ │ - Pan           │ │                        │
│                    │ │ - Select        │ │                        │
│                    │ │ - Measure       │ │                        │
│                    │ │ - Zoom          │ │                        │
│                    │ └─────────────────┘ │                        │
│                    └─────────────────────┘                        │
└────────────────────────────────────────────────────────────────────┘
```

## State Management Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Pinia Store (mapStore.js)                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  State:                                                              │
│  ├─ map: Map instance                                               │
│  ├─ layers: Array of layer configs                                  │
│  ├─ selectedLayer: Currently selected layer ID                      │
│  ├─ viewport: { center, zoom, rotation }                            │
│  ├─ basemap: Base map identifier                                    │
│  └─ availableBasemaps: Array of base map configs                    │
│                                                                      │
│  Actions:                                Getters:                    │
│  ├─ setMap(map)                         ├─ getLayerById(id)        │
│  ├─ addLayer(layer)                     └─ visibleLayers            │
│  ├─ removeLayer(id)                                                 │
│  ├─ updateLayerOrder(layers)                                        │
│  ├─ selectLayer(id)                                                 │
│  ├─ updateViewport(viewport)                                        │
│  ├─ setBasemap(id)                                                  │
│  ├─ updateLayerStyle(id, style)                                     │
│  └─ toggleLayerVisibility(id)                                       │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
              ▲                                    │
              │                                    │
              │ Read State                         │ Dispatch Actions
              │                                    │
              │                                    ▼
┌─────────────┴────────────────────────────────────────────────┐
│                    Vue Components                             │
│  (MapComponent, LayerPanel, StyleEditor, ToolPanel)          │
└──────────────────────────────────────────────────────────────┘
```

## Data Flow: Creating a Map

```
1. User Action
   │
   ├─► Click "Add Layer" in LayerPanel
   │
2. Component Updates Store
   │
   ├─► LayerPanel.vue calls mapStore.addLayer()
   │
3. Store Updates State
   │
   ├─► mapStore.layers.push(newLayer)
   │
4. Reactive Update
   │
   ├─► MapComponent watches layers
   │
5. Map Updates
   │
   ├─► MapComponent adds layer to OpenLayers map
   │
6. User Saves
   │
   ├─► Click "Save Map" in MapBuilder
   │
7. API Call
   │
   ├─► POST /api/maps with map data
   │
8. Backend Processing
   │
   ├─► MapController.store() validates & saves
   │
9. Database Storage
   │
   └─► Map record created in PostgreSQL
```

## Layer Types Support

```
┌────────────────────────────────────────────────────────────────┐
│                     Supported Layer Types                       │
├────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. WMS (Web Map Service)                                      │
│     ├─ Used for: Published GeoServer layers                   │
│     ├─ Protocol: HTTP WMS 1.1.0/1.3.0                         │
│     ├─ Format: Tiled PNG/JPEG                                 │
│     └─ Example: GeoServer workspace:layername                 │
│                                                                 │
│  2. WFS (Web Feature Service)                                  │
│     ├─ Used for: Vector features from GeoServer               │
│     ├─ Protocol: HTTP WFS 1.0.0/2.0.0                         │
│     ├─ Format: GeoJSON/GML                                    │
│     └─ Example: Feature queries and filtering                 │
│                                                                 │
│  3. Vector (GeoJSON)                                           │
│     ├─ Used for: Direct GeoJSON data                          │
│     ├─ Protocol: HTTP                                         │
│     ├─ Format: GeoJSON                                        │
│     └─ Example: Layer GeoJSON endpoints                       │
│                                                                 │
└────────────────────────────────────────────────────────────────┘
```

## Security & Authorization Flow

```
┌────────────────────────────────────────────────────────────────┐
│                    Request Flow                                 │
└────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  1. Authentication (Middleware: auth)                           │
│     └─ Check if user is logged in                             │
└────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  2. Organization Check (Middleware: organization)               │
│     └─ Verify user belongs to organization                     │
└────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  3. Authorization (Controller)                                  │
│     ├─ Check map.organization_id == user.organization_id       │
│     └─ OR map.is_public == true (for viewing)                  │
└────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  4. Action Execution                                            │
│     └─ Perform requested operation                             │
└────────────────────────────────────────────────────────────────┘
```

## Sharing Mechanism

```
Private Map                              Public Map
┌──────────────┐                        ┌──────────────┐
│ is_public: 0 │                        │ is_public: 1 │
│ share_token: │                        │ share_token: │
│   abc123...  │                        │   xyz789...  │
└──────────────┘                        └──────────────┘
       │                                        │
       │                                        │
       ▼                                        ▼
  Access via                              Access via
  /maps/{id}                          /maps/shared/{token}
       │                                        │
       │                                        │
       ▼                                        ▼
 Auth Required                             No Auth Required
 Org Check                                 Token Validation
       │                                        │
       │                                        │
       ▼                                        ▼
   View Map                                 View Map
 (Full Interface)                      (Minimal Interface)
```

## Integration with Existing Systems

```
┌────────────────────────────────────────────────────────────────┐
│                      Laravel GIS Application                    │
├────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐      ┌──────────────┐      ┌────────────┐  │
│  │ Data Import  │      │    Layers    │      │  GeoServer │  │
│  │   System     │─────►│  Management  │─────►│ Integration│  │
│  └──────────────┘      └──────┬───────┘      └─────┬──────┘  │
│                                │                     │         │
│                                │                     │         │
│                                ▼                     │         │
│                        ┌───────────────┐            │         │
│                        │  Map Builder  │◄───────────┘         │
│                        │               │                       │
│                        │ - Uses layers │                       │
│                        │ - WMS from    │                       │
│                        │   GeoServer   │                       │
│                        └───────────────┘                       │
│                                                                 │
└────────────────────────────────────────────────────────────────┘
```

## File Structure

```
laravel-gis/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── MapController.php
│   └── Models/
│       └── Map.php
├── database/
│   └── migrations/
│       └── 2025_10_08_230000_create_maps_table.php
├── resources/
│   ├── js/
│   │   ├── map-builder.js
│   │   ├── stores/
│   │   │   └── mapStore.js
│   │   └── components/
│   │       └── map-builder/
│   │           ├── MapBuilder.vue
│   │           ├── MapComponent.vue
│   │           ├── LayerPanel.vue
│   │           ├── ToolPanel.vue
│   │           └── StyleEditor.vue
│   └── views/
│       └── maps/
│           ├── builder.blade.php
│           ├── index.blade.php
│           ├── show.blade.php
│           ├── share.blade.php
│           └── shared.blade.php
├── routes/
│   ├── web.php
│   └── api.php
├── public/
│   └── build/
│       └── assets/
│           ├── map-builder-*.js
│           └── map-builder-*.css
├── MAP_BUILDER_DOCUMENTATION.md
└── MAP_BUILDER_IMPLEMENTATION_SUMMARY.md
```

## Technology Stack

```
Frontend:
├── Vue 3 (Composition API)
├── Pinia (State Management)
├── OpenLayers 9 (Mapping Library)
├── vuedraggable (Drag & Drop)
└── Vite (Build Tool)

Backend:
├── Laravel 12
├── PostgreSQL with PostGIS
├── Eloquent ORM
└── RESTful API

Integration:
├── GeoServer (WMS/WFS)
├── Existing Layer System
└── Authentication System
```

This architecture provides a robust, scalable, and maintainable solution for the map builder functionality.
