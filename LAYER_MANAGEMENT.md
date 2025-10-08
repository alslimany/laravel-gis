# Layer Management System

This document describes the comprehensive layer management system implemented in Laravel GIS.

## Overview

The layer management system provides a complete interface for managing geographic layers, including:
- CRUD operations for layers
- GeoServer integration for publishing layers
- Attribute table viewer for feature data
- Style configuration and management
- Organization-based access control

## Features

### 1. Layer Management

#### Creating Layers
- Navigate to **Layers** → **New Layer**
- Fill in required fields:
  - **Name**: Display name for the layer
  - **Description**: Optional description
  - **Project**: Optional project association
  - **Table Name**: PostGIS table containing the layer data
  - **Geometry Type**: Point, LineString, Polygon, etc. (auto-detected if not specified)

#### Viewing Layers
- **Layers Index**: View all layers in your organization with pagination
- **Layer Details**: View comprehensive information about a layer including:
  - General information (name, description, project, table name, geometry type)
  - Publishing status (published/unpublished, GeoServer details)
  - Feature count
  - Style configuration

#### Editing Layers
- Update layer metadata (name, description, project)
- Configure layer styles:
  - Fill color
  - Stroke color
  - Stroke width
  - Fill opacity
- Table name and geometry type cannot be changed after creation

#### Deleting Layers
- Requires admin role
- Automatically cleans up GeoServer layers if published
- Permanently removes layer record

### 2. GeoServer Integration

#### Publishing Layers
- Click **Publish to GeoServer** button on layer details page
- Layer is automatically published to organization's GeoServer workspace
- Published layers receive:
  - GeoServer layer name
  - Workspace assignment
  - Publishing timestamp

#### Unpublishing Layers
- Click **Unpublish** button on layer details page
- Removes layer from GeoServer
- Clears publishing metadata

#### Style Updates
- Edit styles through the layer edit page
- Style changes can be synchronized to GeoServer
- Supports:
  - Fill and stroke colors
  - Line widths
  - Opacity settings

### 3. Attribute Table Interface

Access the attribute table for any layer to:
- View all feature attributes
- Search across all columns
- Paginate through large datasets
- See geometry data as WKT
- Filter and sort data

**Features:**
- **Search**: Full-text search across all non-geometry columns
- **Pagination**: Adjustable page size (10, 25, 50, 100 records)
- **Statistics**: View layer statistics (total features, attributes, geometry type)
- **Responsive**: Sticky header for easy scrolling

### 4. API Endpoints

#### RESTful Layer API
```
GET    /layers                   - List all layers
GET    /layers/create            - Show create form
POST   /layers                   - Create new layer
GET    /layers/{layer}           - View layer details
GET    /layers/{layer}/edit      - Show edit form
PUT    /layers/{layer}           - Update layer
DELETE /layers/{layer}           - Delete layer
```

#### GeoServer Operations
```
POST /layers/{layer}/publish     - Publish to GeoServer
POST /layers/{layer}/unpublish   - Unpublish from GeoServer
POST /layers/{layer}/style       - Update layer style
```

#### Data Access
```
GET /layers/{layer}/attributes   - View attribute table
GET /layers/{layer}/geojson      - Download as GeoJSON
```

## Database Schema

### Layers Table

```sql
CREATE TABLE layers (
    id BIGINT PRIMARY KEY,
    project_id BIGINT NULL,                    -- Optional project association
    user_id BIGINT NOT NULL,                   -- Creator
    organization_id BIGINT NOT NULL,           -- Organization ownership
    name VARCHAR(255) NOT NULL,                -- Display name
    description TEXT NULL,                     -- Optional description
    table_name VARCHAR(255) NOT NULL,          -- PostGIS table name
    geometry_type VARCHAR(50) NULL,            -- Point, LineString, Polygon, etc.
    feature_count INT DEFAULT 0,               -- Number of features
    style_config JSON NULL,                    -- Style configuration
    geoserver_layer_name VARCHAR(255) NULL,    -- GeoServer layer name
    geoserver_workspace VARCHAR(255) NULL,     -- GeoServer workspace
    published BOOLEAN DEFAULT FALSE,           -- Publishing status
    published_at TIMESTAMP NULL,               -- Publishing timestamp
    metadata JSON NULL,                        -- Additional metadata
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    
    INDEX idx_project_id (project_id),
    INDEX idx_user_id (user_id),
    INDEX idx_organization_id (organization_id),
    INDEX idx_published (published)
);
```

## Models and Relationships

### Layer Model

```php
use App\Models\Layer;

// Create a layer
$layer = Layer::create([
    'name' => 'My Layer',
    'description' => 'A sample layer',
    'user_id' => auth()->id(),
    'organization_id' => auth()->user()->organization_id,
    'table_name' => 'my_layer_table',
    'geometry_type' => 'Point',
]);

// Relationships
$layer->user;           // Get creator
$layer->organization;   // Get organization
$layer->project;        // Get associated project (nullable)

// Publishing
$layer->markAsPublished('layer_name', 'workspace_name');
$layer->markAsUnpublished();
$layer->isPublished();  // Check if published

// Scopes
Layer::published()->get();                           // Get only published layers
Layer::forOrganization($organizationId)->get();     // Get layers for organization
```

## Authorization

Access control is managed through the `LayerPolicy`:

### Permissions

- **View Any**: Requires organization membership
- **View**: Must be in same organization as layer
- **Create**: Requires organization membership + admin/editor role
- **Update**: Requires organization membership + admin/editor role
- **Delete**: Requires organization membership + admin role

### Example Authorization

```php
// Check if user can create layers
if (auth()->user()->can('create', Layer::class)) {
    // Show create form
}

// Check if user can update a layer
if (auth()->user()->can('update', $layer)) {
    // Allow editing
}
```

## Usage Examples

### Creating a Layer from Imported Data

```php
use App\Models\Layer;
use App\Models\DataImport;

$import = DataImport::find($importId);

if ($import->isCompleted()) {
    $layer = Layer::create([
        'name' => $import->file_name,
        'description' => "Imported from {$import->file_name}",
        'user_id' => auth()->id(),
        'organization_id' => auth()->user()->organization_id,
        'table_name' => $import->table_name,
        'geometry_type' => $import->geometry_type,
        'feature_count' => $import->feature_count,
    ]);
    
    // Optionally publish to GeoServer
    $layer->organization->publishLayerToGeoServer($layer->table_name, [
        'title' => $layer->name,
        'abstract' => $layer->description,
    ]);
    
    $layer->markAsPublished($layer->table_name, $layer->organization->getGeoServerWorkspace());
}
```

### Publishing Multiple Layers

```php
use App\Models\Layer;

$layers = Layer::where('organization_id', auth()->user()->organization_id)
    ->where('published', false)
    ->get();

foreach ($layers as $layer) {
    try {
        $layer->organization->publishLayerToGeoServer($layer->table_name, [
            'title' => $layer->name,
            'abstract' => $layer->description ?? "Layer: {$layer->name}",
        ]);
        
        $layer->markAsPublished(
            $layer->table_name, 
            $layer->organization->getGeoServerWorkspace()
        );
    } catch (\Exception $e) {
        \Log::error("Failed to publish layer {$layer->id}: {$e->getMessage()}");
    }
}
```

### Accessing Layer Data

```php
use Illuminate\Support\Facades\DB;

$layer = Layer::find($layerId);

// Get all features
$features = DB::table($layer->table_name)->get();

// Get features as GeoJSON
$geojson = DB::select("
    SELECT jsonb_build_object(
        'type', 'FeatureCollection',
        'features', jsonb_agg(
            jsonb_build_object(
                'type', 'Feature',
                'geometry', ST_AsGeoJSON(geom)::jsonb,
                'properties', to_jsonb(row) - 'geom'
            )
        )
    ) as geojson
    FROM (SELECT * FROM {$layer->table_name}) row
")[0]->geojson;
```

## Style Configuration

Layer styles are stored as JSON and can include:

```json
{
    "fillColor": "#AAAAAA",
    "strokeColor": "#000000",
    "strokeWidth": 1,
    "fillOpacity": 0.5,
    "pointRadius": 6,
    "pointSymbol": "circle"
}
```

### Updating Styles

```php
$layer->update([
    'style_config' => [
        'fillColor' => '#FF0000',
        'strokeColor' => '#000000',
        'strokeWidth' => 2,
        'fillOpacity' => 0.7,
    ],
]);

// Sync to GeoServer if published
if ($layer->isPublished()) {
    $layer->organization->updateLayerStyle(
        $layer->geoserver_layer_name,
        'custom_style',
        $sldContent
    );
}
```

## Testing

Comprehensive tests are available in `tests/Feature/LayerTest.php`:

```bash
php artisan test --filter LayerTest
```

Tests cover:
- CRUD operations
- Authorization policies
- Organization isolation
- GeoServer integration
- Style management
- Attribute table access

## Best Practices

1. **Always Associate with Organization**: Layers must belong to an organization for proper access control
2. **Link to Projects**: Associate layers with projects for better organization
3. **Use Descriptive Names**: Clear names help users identify layers quickly
4. **Configure Styles**: Set appropriate styles before publishing to GeoServer
5. **Monitor Feature Counts**: Keep track of layer sizes for performance
6. **Test Before Publishing**: Verify layer data before making it available via GeoServer
7. **Clean Up**: Delete unused layers to maintain database efficiency

## Troubleshooting

### Layer Creation Fails
- Verify the PostGIS table exists
- Check table name spelling
- Ensure user has organization membership

### Publishing Fails
- Verify GeoServer connection settings
- Check workspace exists
- Ensure organization has GeoServer workspace configured

### Attribute Table Empty
- Verify table contains data
- Check geometry column exists
- Ensure proper database permissions

### Authorization Denied
- Verify user has appropriate role (admin/editor)
- Check organization membership
- Ensure layer belongs to user's organization

## Related Documentation

- [Data Import System](DATA_IMPORT.md)
- [GeoServer Integration](GEOSERVER_INTEGRATION.md)
- [Spatial Features](SPATIAL_FEATURES.md)
- [Authorization Guide](AUTH_GUIDE.md)
