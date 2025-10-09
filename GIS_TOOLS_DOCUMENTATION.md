# GIS Tools and Analysis Documentation

This document describes the GIS tools and analysis capabilities added to the Laravel GIS application.

## Overview

The application now includes comprehensive GIS analysis and export tools that allow users to:
- Perform spatial measurements (distance, area)
- Conduct spatial analysis (buffer, intersections, spatial queries)
- Execute attribute queries
- Draw and edit features on the map
- Export data in multiple formats

## Components

### 1. Backend Components

#### SpatialHelper Extensions
Located in `app/Helpers/SpatialHelper.php`

**New Methods:**
- `buffer($wkt, $distance)` - Create a buffer around a geometry
- `intersects($wkt1, $wkt2)` - Check if two geometries intersect
- `contains($wkt1, $wkt2)` - Check if one geometry contains another
- `within($wkt1, $wkt2)` - Check if one geometry is within another
- `area($wkt)` - Calculate area in square meters
- `length($wkt)` - Calculate length/perimeter in meters
- `makeLineString($coordinates)` - Create a LineString from coordinates

**Example Usage:**
```php
use App\Helpers\SpatialHelper;

// Create a buffer
$buffered = SpatialHelper::buffer('POINT(0 0)', 100);

// Check intersection
$intersects = SpatialHelper::intersects($polygon1, $polygon2);

// Calculate area
$area = SpatialHelper::area('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))');
```

#### QueryBuilder Helper
Located in `app/Helpers/QueryBuilder.php`

Provides methods for building spatial and attribute queries:

- `withinDistance($tableName, $geometryColumn, $lat, $lon, $distance)` - Find features within distance
- `intersects($tableName, $geometryColumn, $wkt)` - Find features that intersect
- `within($tableName, $geometryColumn, $wkt)` - Find features within a geometry
- `contains($tableName, $geometryColumn, $wkt)` - Find features that contain a geometry
- `attributeQuery($tableName, $conditions, $geometryColumn)` - Query by attributes
- `bufferAnalysis($tableName, $geometryColumn, $distance, $conditions)` - Buffer analysis on layer

**Example Usage:**
```php
use App\Helpers\QueryBuilder;

// Find features within 1000 meters
$results = QueryBuilder::withinDistance(
    'users', 
    'location', 
    37.7749, 
    -122.4194, 
    1000
);

// Attribute query
$results = QueryBuilder::attributeQuery('projects', [
    ['column' => 'status', 'operator' => '=', 'value' => 'active'],
    ['column' => 'budget', 'operator' => '>', 'value' => 10000]
], 'bounding_box');
```

#### AnalysisController
Located in `app/Http/Controllers/AnalysisController.php`

**API Endpoints:**

1. **Buffer Analysis**
   - POST `/api/analysis/buffer`
   - Parameters: `wkt`, `distance`
   - Returns: Buffered geometry in WKT and GeoJSON

2. **Spatial Query**
   - POST `/api/analysis/spatial-query`
   - Parameters: `layer_id`, `operation` (within/contains/intersects), `wkt`
   - Returns: Features matching spatial criteria

3. **Attribute Query**
   - POST `/api/analysis/attribute-query`
   - Parameters: `layer_id`, `conditions` array
   - Returns: Features matching attribute criteria

4. **Measure Distance**
   - POST `/api/analysis/measure-distance`
   - Parameters: `lat1`, `lon1`, `lat2`, `lon2`
   - Returns: Distance in meters, km, and miles

5. **Measure Area**
   - POST `/api/analysis/measure-area`
   - Parameters: `wkt`
   - Returns: Area in sq meters, sq km, hectares, and acres

6. **Layer Buffer**
   - POST `/api/analysis/layer-buffer`
   - Parameters: `layer_id`, `distance`, `conditions` (optional)
   - Returns: Buffered features from layer

**Example Request:**
```javascript
// Measure distance
const response = await fetch('/api/analysis/measure-distance', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        lat1: 37.7749,
        lon1: -122.4194,
        lat2: 37.7849,
        lon2: -122.4094
    })
});

const data = await response.json();
console.log(data.distance_km); // Distance in kilometers
```

#### ExportController
Located in `app/Http/Controllers/ExportController.php`

**Endpoints:**

1. **Export Layer as GeoJSON**
   - GET `/export/layer/{layer}/geojson`
   - Downloads layer data as GeoJSON file

2. **Export Layer as CSV**
   - GET `/export/layer/{layer}/csv`
   - Downloads layer data as CSV file with WKT geometry

3. **Export Map Configuration**
   - GET `/export/map/{map}/config`
   - Downloads map configuration as JSON

4. **Export Query Results**
   - POST `/api/export/query-results`
   - Parameters: `layer_id`, `features` array
   - Downloads filtered results as GeoJSON

**Example Usage:**
```javascript
// Export layer as GeoJSON
window.location.href = `/export/layer/${layerId}/geojson`;

// Export map configuration
window.location.href = `/export/map/${mapId}/config`;
```

### 2. Frontend Components

#### ToolPanel.vue
Located in `resources/js/components/map-builder/ToolPanel.vue`

**Enhanced Tools:**
- Pan
- Select features
- Draw Point
- Draw Line
- Draw Polygon
- Measure Distance
- Measure Area
- Identify/Show Coordinates
- Zoom In/Out
- Zoom to Extent

**Events:**
- `tool-selected` - Emitted when a tool is activated

#### AnalysisPanel.vue
Located in `resources/js/components/map-builder/AnalysisPanel.vue`

**Features:**

1. **Spatial Analysis Section**
   - Buffer Analysis: Input distance and create buffer
   - Spatial Query: Select operation (within/contains/intersects)

2. **Attribute Query Section**
   - Dynamic query builder
   - Add/remove conditions
   - Support for multiple operators (=, !=, >, <, >=, <=, LIKE)

3. **Export Section**
   - Export as GeoJSON
   - Export as CSV
   - Export Map Config
   - Export as Image (PNG)

4. **Results Display**
   - Shows feature count
   - Lists first 5 features
   - Indicates if more features exist

**Props:**
- `selectedLayer` - Currently selected layer object
- `selectedGeometry` - Currently drawn/selected geometry in WKT

**Events:**
- `close` - Emitted when panel is closed
- `analysis-complete` - Emitted when analysis completes with results

## Usage Examples

### 1. Measuring Distance

```javascript
// Using the API directly
const response = await fetch('/api/analysis/measure-distance', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
        lat1: 37.7749,
        lon1: -122.4194,
        lat2: 37.7849,
        lon2: -122.4094
    })
});

const result = await response.json();
console.log(`Distance: ${result.distance_km} km`);
```

### 2. Buffer Analysis

```javascript
// Create a buffer around a point
const response = await fetch('/api/analysis/buffer', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        wkt: 'POINT(-122.4194 37.7749)',
        distance: 1000 // 1000 meters
    })
});

const result = await response.json();
// result.buffered_geojson contains the buffered polygon
```

### 3. Spatial Query

```javascript
// Find features within a polygon
const response = await fetch('/api/analysis/spatial-query', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        layer_id: 1,
        operation: 'within',
        wkt: 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))'
    })
});

const result = await response.json();
console.log(`Found ${result.count} features`);
```

### 4. Attribute Query

```javascript
// Query features by attributes
const response = await fetch('/api/analysis/attribute-query', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        layer_id: 1,
        conditions: [
            { column: 'status', operator: '=', value: 'active' },
            { column: 'area', operator: '>', value: 1000 }
        ]
    })
});

const result = await response.json();
console.log(`Found ${result.count} matching features`);
```

### 5. Exporting Data

```javascript
// Export layer as GeoJSON
window.location.href = `/export/layer/${layerId}/geojson`;

// Export layer as CSV
window.location.href = `/export/layer/${layerId}/csv`;

// Export map configuration
window.location.href = `/export/map/${mapId}/config`;
```

## Authorization

All analysis and export endpoints require:
1. User authentication
2. Organization membership
3. Authorization to access the specific layer/map

Users can only access layers and maps within their organization.

## Testing

Tests are provided in:
- `tests/Unit/SpatialHelperTest.php` - Tests for spatial helper methods
- `tests/Feature/AnalysisTest.php` - Tests for analysis endpoints
- `tests/Feature/ExportTest.php` - Tests for export endpoints

**Note:** Tests require PostGIS database. SQLite tests will fail for spatial operations.

## Future Enhancements

Potential improvements:
1. WFS-T integration for GeoServer feature editing
2. Advanced drawing tools with snapping
3. Topology validation
4. 3D analysis capabilities
5. Batch processing for large datasets
6. Scheduled analysis jobs
7. Analysis result caching
8. Custom CRS support
9. Network analysis (routing, service areas)
10. Raster analysis tools

## Performance Considerations

1. **Large Datasets**: Buffer and spatial queries on large datasets may take time
2. **Distance Units**: All distances are in meters by default
3. **Coordinate System**: All operations use SRID 4326 (WGS84)
4. **Export Limits**: Consider pagination for large exports
5. **Memory**: Image export may use significant memory for large maps

## Troubleshooting

### Common Issues

**Issue:** Spatial functions not working
**Solution:** Ensure PostGIS extension is installed: `CREATE EXTENSION postgis;`

**Issue:** Export fails with 500 error
**Solution:** Check if the table exists and has a geometry column

**Issue:** Buffer analysis returns empty result
**Solution:** Verify WKT syntax and that distance is positive

**Issue:** Image export not working
**Solution:** Ensure all map layers are fully loaded before export

## API Reference

### Analysis Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/analysis/buffer` | POST | Yes | Create buffer around geometry |
| `/api/analysis/spatial-query` | POST | Yes | Query features spatially |
| `/api/analysis/attribute-query` | POST | Yes | Query features by attributes |
| `/api/analysis/measure-distance` | POST | Yes | Calculate distance between points |
| `/api/analysis/measure-area` | POST | Yes | Calculate area of polygon |
| `/api/analysis/layer-buffer` | POST | Yes | Buffer analysis on layer |

### Export Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/export/layer/{layer}/geojson` | GET | Yes | Export layer as GeoJSON |
| `/export/layer/{layer}/csv` | GET | Yes | Export layer as CSV |
| `/export/map/{map}/config` | GET | Yes | Export map configuration |
| `/api/export/map/{map}/prepare` | GET | Yes | Prepare map data for export |
| `/api/export/query-results` | POST | Yes | Export query results |

## Support

For issues or questions:
- Review this documentation
- Check Laravel logs for errors
- Verify database connection and PostGIS installation
- Check browser console for JavaScript errors
- Ensure proper authentication and authorization

## License

This GIS Tools module is part of the Laravel GIS project and follows the same license terms.
