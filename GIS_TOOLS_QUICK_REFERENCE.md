# GIS Tools Quick Reference

Quick reference guide for using the GIS tools and analysis features.

## Quick Start

### 1. Measure Distance
```javascript
fetch('/api/analysis/measure-distance', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        lat1: 37.7749, lon1: -122.4194,
        lat2: 37.7849, lon2: -122.4094
    })
}).then(r => r.json()).then(d => console.log(d.distance_km));
```

### 2. Measure Area
```javascript
fetch('/api/analysis/measure-area', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        wkt: 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))'
    })
}).then(r => r.json()).then(d => console.log(d.area_sqkm));
```

### 3. Create Buffer
```javascript
fetch('/api/analysis/buffer', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        wkt: 'POINT(0 0)',
        distance: 1000
    })
}).then(r => r.json()).then(d => console.log(d.buffered_geojson));
```

### 4. Spatial Query
```javascript
fetch('/api/analysis/spatial-query', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        layer_id: 1,
        operation: 'within', // or 'contains', 'intersects'
        wkt: 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))'
    })
}).then(r => r.json()).then(d => console.log(d.features));
```

### 5. Attribute Query
```javascript
fetch('/api/analysis/attribute-query', {
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
}).then(r => r.json()).then(d => console.log(d.features));
```

## PHP Backend Usage

### SpatialHelper Examples
```php
use App\Helpers\SpatialHelper;

// Distance
$distance = SpatialHelper::distance(37.7749, -122.4194, 37.7849, -122.4094);

// Buffer
$buffered = SpatialHelper::buffer('POINT(0 0)', 1000);

// Area
$area = SpatialHelper::area('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))');

// Check intersection
$intersects = SpatialHelper::intersects($polygon1, $polygon2);

// Check contains
$contains = SpatialHelper::contains($outer, $inner);
```

### QueryBuilder Examples
```php
use App\Helpers\QueryBuilder;

// Find nearby features
$results = QueryBuilder::withinDistance(
    'users', 'location', 37.7749, -122.4194, 1000
);

// Spatial query
$results = QueryBuilder::intersects(
    'projects', 'geometry', 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))'
);

// Attribute query
$results = QueryBuilder::attributeQuery('layers', [
    ['column' => 'status', 'operator' => '=', 'value' => 'active']
], 'geometry');
```

## Export Data

### GeoJSON Export
```javascript
// Direct download
window.location.href = `/export/layer/${layerId}/geojson`;
```

### CSV Export
```javascript
// Direct download
window.location.href = `/export/layer/${layerId}/csv`;
```

### Map Config Export
```javascript
// Direct download
window.location.href = `/export/map/${mapId}/config`;
```

### Image Export (PNG)
```javascript
// Client-side canvas export
const map = mapStore.map;
map.once('rendercomplete', () => {
    const canvas = document.createElement('canvas');
    const size = map.getSize();
    canvas.width = size[0];
    canvas.height = size[1];
    const context = canvas.getContext('2d');
    
    document.querySelectorAll('.ol-layer canvas').forEach(c => {
        if (c.width > 0) {
            context.drawImage(c, 0, 0);
        }
    });
    
    canvas.toBlob(blob => {
        const link = document.createElement('a');
        link.download = 'map.png';
        link.href = URL.createObjectURL(blob);
        link.click();
    });
});
map.renderSync();
```

## Vue Component Usage

### Using ToolPanel
```vue
<template>
    <ToolPanel 
        :map="mapInstance" 
        @tool-selected="handleToolSelection" 
    />
</template>

<script setup>
const handleToolSelection = (toolId) => {
    console.log('Selected tool:', toolId);
    // toolId can be: 'pan', 'select', 'draw-point', 'draw-line', 
    // 'draw-polygon', 'measure-distance', 'measure-area', etc.
};
</script>
```

### Using AnalysisPanel
```vue
<template>
    <AnalysisPanel 
        v-if="showAnalysis"
        :selected-layer="currentLayer"
        :selected-geometry="drawnGeometry"
        @close="showAnalysis = false"
        @analysis-complete="handleResults"
    />
</template>

<script setup>
const handleResults = (result) => {
    console.log('Analysis type:', result.type);
    console.log('Result data:', result.result);
};
</script>
```

## Common WKT Formats

```javascript
// Point
const point = 'POINT(-122.4194 37.7749)';

// LineString
const line = 'LINESTRING(-122.5 37.7, -122.4 37.8, -122.3 37.9)';

// Polygon
const polygon = 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))';

// Polygon with hole
const polygonWithHole = 'POLYGON((0 0, 4 0, 4 4, 0 4, 0 0), (1 1, 2 1, 2 2, 1 2, 1 1))';

// MultiPoint
const multiPoint = 'MULTIPOINT(0 0, 1 1, 2 2)';

// MultiLineString
const multiLine = 'MULTILINESTRING((0 0, 1 1), (2 2, 3 3))';

// MultiPolygon
const multiPolygon = 'MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)), ((2 2, 3 2, 3 3, 2 3, 2 2)))';
```

## API Endpoints Summary

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/analysis/buffer` | POST | Create buffer |
| `/api/analysis/spatial-query` | POST | Spatial query |
| `/api/analysis/attribute-query` | POST | Attribute query |
| `/api/analysis/measure-distance` | POST | Measure distance |
| `/api/analysis/measure-area` | POST | Measure area |
| `/api/analysis/layer-buffer` | POST | Layer buffer |
| `/export/layer/{id}/geojson` | GET | Export GeoJSON |
| `/export/layer/{id}/csv` | GET | Export CSV |
| `/export/map/{id}/config` | GET | Export config |

## Unit Conversions

### Distance
- Meters (default)
- Kilometers: `meters / 1000`
- Miles: `meters / 1609.34`
- Feet: `meters * 3.28084`

### Area
- Square Meters (default)
- Square Kilometers: `sqm / 1000000`
- Hectares: `sqm / 10000`
- Acres: `sqm / 4046.86`
- Square Miles: `sqm / 2589988.11`

## Common Operators

For attribute queries:
- `=` - Equal
- `!=` - Not equal
- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal
- `LIKE` - Pattern matching (use % wildcards)

## Error Handling

```javascript
try {
    const response = await fetch('/api/analysis/buffer', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ wkt: 'POINT(0 0)', distance: 1000 })
    });
    
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const data = await response.json();
    
    if (data.error) {
        console.error('API error:', data.error);
        return;
    }
    
    // Process successful result
    console.log(data);
    
} catch (error) {
    console.error('Request failed:', error);
    alert('Operation failed. Please try again.');
}
```

## Performance Tips

1. **Large Buffers**: Buffer operations on large distances may be slow
2. **Complex Geometries**: Simplify geometries when possible
3. **Batch Queries**: Consider pagination for large result sets
4. **Index Usage**: Ensure spatial indexes exist on geometry columns
5. **Caching**: Cache frequently-used analysis results

## Troubleshooting

### "No such function: ST_*"
**Problem**: PostGIS not installed
**Solution**: `CREATE EXTENSION postgis;`

### "Unauthorized" (403)
**Problem**: User not in same organization as layer/map
**Solution**: Verify user has access to the resource

### "Invalid WKT"
**Problem**: Malformed WKT string
**Solution**: Validate WKT format, ensure coordinates are (lon, lat)

### Export fails
**Problem**: Table doesn't exist or no geometry column
**Solution**: Verify layer table exists and has geometry column

## Best Practices

1. Always validate user input before API calls
2. Handle errors gracefully with user-friendly messages
3. Show loading indicators for long-running operations
4. Use appropriate distance/area units for your use case
5. Test spatial queries with small datasets first
6. Cache analysis results when appropriate
7. Use organization-scoped queries for security
8. Validate geometries before spatial operations

## See Also

- [Full Documentation](GIS_TOOLS_DOCUMENTATION.md)
- [Implementation Summary](GIS_TOOLS_IMPLEMENTATION_SUMMARY.md)
- [Map Builder Documentation](MAP_BUILDER_DOCUMENTATION.md)
- [Spatial Features Documentation](SPATIAL_FEATURES.md)
