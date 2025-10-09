# API Documentation

Complete API reference for the Laravel WebGIS application.

## Table of Contents

- [Authentication](#authentication)
- [Health Check Endpoints](#health-check-endpoints)
- [Analysis Endpoints](#analysis-endpoints)
- [Export Endpoints](#export-endpoints)
- [Layer Management](#layer-management)
- [Map Management](#map-management)
- [Data Import](#data-import)
- [Response Formats](#response-formats)
- [Error Handling](#error-handling)

## Authentication

All API endpoints (except health checks and public shared views) require authentication using Laravel Sanctum or session-based authentication.

### Headers

```http
Authorization: Bearer {your-api-token}
Content-Type: application/json
Accept: application/json
```

## Health Check Endpoints

### Basic Health Check

```http
GET /health
```

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2025-10-09T23:41:24+00:00"
}
```

### Detailed Health Check

```http
GET /health/detailed
```

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2025-10-09T23:41:24+00:00",
  "checks": {
    "app": {
      "status": "healthy",
      "environment": "production",
      "debug": false,
      "version": "1.0.0"
    },
    "database": {
      "status": "healthy",
      "connection": "ok",
      "postgis": "enabled",
      "version": "3.1.4"
    },
    "cache": {
      "status": "healthy",
      "driver": "redis"
    },
    "queue": {
      "status": "healthy",
      "connection": "redis"
    }
  }
}
```

### Performance Metrics

```http
GET /health/metrics
```

**Response:**
```json
{
  "timestamp": "2025-10-09T23:41:24+00:00",
  "memory": {
    "limit": "512M",
    "usage": "45.23 MB",
    "peak": "48.76 MB",
    "usage_percentage": 8.85
  },
  "database": {
    "connections": ["pgsql"]
  },
  "uptime": "5d 12h 34m"
}
```

## Analysis Endpoints

### Buffer Analysis

Create a buffer around a geometry.

```http
POST /api/analysis/buffer
```

**Request Body:**
```json
{
  "geometry": "POINT(-122.4194 37.7749)",
  "distance": 1000,
  "unit": "meters"
}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "type": "Polygon",
    "coordinates": [[...]]
  },
  "area": 3141592.65,
  "perimeter": 6283.18
}
```

### Layer Buffer Analysis

Apply buffer to all features in a layer.

```http
POST /api/analysis/layer-buffer
```

**Request Body:**
```json
{
  "layer_id": 1,
  "distance": 500,
  "unit": "meters"
}
```

### Spatial Query

Perform spatial relationship queries.

```http
POST /api/analysis/spatial-query
```

**Request Body:**
```json
{
  "layer_id": 1,
  "geometry": "POLYGON((...  ))",
  "operation": "within"
}
```

**Operations:** `within`, `contains`, `intersects`, `touches`, `overlaps`

### Attribute Query

Query features based on attributes.

```http
POST /api/analysis/attribute-query
```

**Request Body:**
```json
{
  "layer_id": 1,
  "conditions": [
    {
      "field": "population",
      "operator": ">",
      "value": 1000000
    },
    {
      "field": "name",
      "operator": "like",
      "value": "San%"
    }
  ],
  "logic": "AND"
}
```

### Measure Distance

Calculate distance between two points.

```http
POST /api/analysis/measure-distance
```

**Request Body:**
```json
{
  "start": "POINT(-122.4194 37.7749)",
  "end": "POINT(-118.2437 34.0522)",
  "unit": "kilometers"
}
```

**Response:**
```json
{
  "success": true,
  "distance": 559.12,
  "unit": "kilometers"
}
```

### Measure Area

Calculate area of a polygon.

```http
POST /api/analysis/measure-area
```

**Request Body:**
```json
{
  "geometry": "POLYGON((...  ))",
  "unit": "hectares"
}
```

## Export Endpoints

### Export Layer as GeoJSON

```http
POST /api/export/geojson
```

**Request Body:**
```json
{
  "layer_id": 1,
  "filters": {
    "population": { "operator": ">", "value": 100000 }
  }
}
```

**Response:** GeoJSON FeatureCollection

### Export Layer as CSV

```http
POST /api/export/csv
```

**Request Body:**
```json
{
  "layer_id": 1,
  "include_geometry": true,
  "columns": ["id", "name", "population"]
}
```

**Response:** CSV file download

### Export Layer Data

```http
POST /api/export/layer
```

**Request Body:**
```json
{
  "layer_id": 1,
  "format": "geojson",
  "srid": 4326
}
```

### Export Drawing

Export drawn features from the map.

```http
POST /api/export/drawing
```

**Request Body:**
```json
{
  "features": [...],
  "format": "geojson"
}
```

### Export Map Configuration

```http
POST /api/export/map-config
```

**Request Body:**
```json
{
  "map_id": 1
}
```

## Layer Management

### List Layers

```http
GET /api/layers
```

**Query Parameters:**
- `organization_id` - Filter by organization
- `published` - Filter by published status
- `per_page` - Pagination (default: 15)

### Get Layer Details

```http
GET /api/layers/{id}
```

### Create Layer

```http
POST /api/layers
```

**Request Body:**
```json
{
  "name": "My Layer",
  "description": "Layer description",
  "table_name": "my_table",
  "geometry_type": "Point",
  "is_public": false
}
```

### Update Layer

```http
PUT /api/layers/{id}
```

### Delete Layer

```http
DELETE /api/layers/{id}
```

### Publish Layer to GeoServer

```http
POST /api/layers/{id}/publish
```

### Get Layer GeoJSON

```http
GET /api/layers/{id}/geojson
```

**Query Parameters:**
- `bbox` - Bounding box filter: `minx,miny,maxx,maxy`
- `limit` - Maximum features (default: 1000)

## Map Management

### List Maps

```http
GET /api/maps
```

### Get Map

```http
GET /api/maps/{id}
```

### Create Map

```http
POST /api/maps
```

**Request Body:**
```json
{
  "name": "My Map",
  "description": "Map description",
  "config": {
    "center": [-122.4194, 37.7749],
    "zoom": 12,
    "layers": [
      {
        "type": "wms",
        "url": "http://geoserver/wms",
        "layers": "workspace:layer",
        "visible": true
      }
    ]
  },
  "is_public": false
}
```

### Update Map

```http
PUT /api/maps/{id}
```

### Delete Map

```http
DELETE /api/maps/{id}
```

### Share Map

```http
POST /api/maps/{id}/share
```

**Response:**
```json
{
  "success": true,
  "share_token": "abc123xyz",
  "share_url": "https://your-domain.com/maps/shared/abc123xyz"
}
```

## Data Import

### Upload File

```http
POST /api/imports
```

**Request Body:** (multipart/form-data)
- `file` - Spatial data file (Shapefile zip, GeoJSON, KML, CSV)
- `name` - Import name
- `description` - Import description (optional)

**Response:**
```json
{
  "success": true,
  "import_id": 123,
  "status": "processing"
}
```

### Check Import Status

```http
GET /api/imports/{id}/status
```

**Response:**
```json
{
  "id": 123,
  "status": "completed",
  "progress": 100,
  "features_count": 1250,
  "table_name": "import_my_data_20251009",
  "error": null
}
```

**Status Values:** `pending`, `processing`, `completed`, `failed`

### List Imports

```http
GET /api/imports
```

### Delete Import

```http
DELETE /api/imports/{id}
```

## Response Formats

### Success Response

```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed successfully"
}
```

### Paginated Response

```json
{
  "data": [...],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 15,
    "to": 15,
    "total": 75
  }
}
```

## Error Handling

### Error Response Format

```json
{
  "success": false,
  "message": "Error message",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### HTTP Status Codes

- `200` - Success
- `201` - Created
- `204` - No Content
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Internal Server Error
- `503` - Service Unavailable

### Common Error Messages

**Authentication Error:**
```json
{
  "message": "Unauthenticated."
}
```

**Authorization Error:**
```json
{
  "message": "This action is unauthorized."
}
```

**Validation Error:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "layer_id": ["The layer id field is required."],
    "geometry": ["The geometry field must be valid WKT."]
  }
}
```

**Resource Not Found:**
```json
{
  "message": "Resource not found."
}
```

## Rate Limiting

API endpoints are rate limited to prevent abuse:

- **Authenticated:** 60 requests per minute
- **Unauthenticated:** 10 requests per minute

Rate limit headers:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
Retry-After: 45
```

## Webhook Support

Configure webhooks to receive notifications about import completion and other events.

**Webhook Payload:**
```json
{
  "event": "import.completed",
  "data": {
    "import_id": 123,
    "status": "completed",
    "features_count": 1250
  },
  "timestamp": "2025-10-09T23:41:24+00:00"
}
```

## Support

For API support, please:
- Check the [User Guide](USER_GUIDE.md)
- Review [Deployment Guide](DEPLOYMENT_GUIDE.md)
- Open an issue on GitHub
