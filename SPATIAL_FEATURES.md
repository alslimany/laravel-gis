# Spatial Database Features

This Laravel application includes a comprehensive spatial database foundation built on PostGIS. This document describes the available spatial features and how to use them.

## Overview

The application includes:
- PostGIS-enabled PostgreSQL database
- Spatial columns with automatic indexing
- Eloquent models with spatial traits
- Helper functions for spatial operations
- Factory support for generating spatial test data

## Database Schema

### Users Table
- **location**: GEOGRAPHY(POINT, 4326) - User's geographic location
- **Indexed**: GIST index for efficient spatial queries

### Organizations Table
- Standard fields with foreign key to users
- Relationships: belongs to User, has many Projects

### Projects Table
- **bounding_box**: GEOMETRY(POLYGON, 4326) - Project boundary
- **Indexed**: GIST index for efficient spatial queries
- Relationships: belongs to Organization

## Models

### User Model
```php
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Create a user with location
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => bcrypt('password'),
    'location' => DB::raw("ST_MakePoint(-122.4194, 37.7749)::geography")
]);

// Access spatial attributes
$wkt = $user->location_wkt;  // Get as WKT
$geojson = $user->location_geo_json;  // Get as GeoJSON
```

### Organization Model
```php
use App\Models\Organization;

// Create organization
$org = Organization::create([
    'name' => 'My Organization',
    'description' => 'Description',
    'user_id' => $user->id
]);

// Access relationships
$org->user;  // Get owner
$org->projects;  // Get all projects
```

### Project Model
```php
use App\Models\Project;
use Illuminate\Support\Facades\DB;

// Create project with bounding box
$project = Project::create([
    'name' => 'My Project',
    'description' => 'Description',
    'organization_id' => $org->id,
    'bounding_box' => DB::raw("ST_GeomFromText('POLYGON((-122.5 37.7, -122.3 37.7, -122.3 37.8, -122.5 37.8, -122.5 37.7))', 4326)")
]);

// Access spatial attributes
$wkt = $project->bounding_box_wkt;  // Get as WKT
$geojson = $project->bounding_box_geo_json;  // Get as GeoJSON
```

## Spatial Trait Methods

All models with spatial columns can use the SpatialTrait for common operations:

### Query Scopes

#### withinDistance
Find records within a specified distance from a point:
```php
// Find users within 10km of San Francisco
$users = User::withinDistance('location', 37.7749, -122.4194, 10000)->get();
```

#### near
Find records near a point, ordered by distance:
```php
// Find nearest users to a point
$users = User::near('location', 37.7749, -122.4194)->take(10)->get();

// Access distance for each user
foreach ($users as $user) {
    echo "Distance: " . ($user->distance / 1000) . " km\n";
}
```

#### withinPolygon
Find records within a polygon:
```php
$polygon = "POLYGON((-122.5 37.7, -122.3 37.7, -122.3 37.8, -122.5 37.8, -122.5 37.7))";
$users = User::withinPolygon('location', $polygon)->get();
```

#### intersects
Find records that intersect with a geometry:
```php
$geometry = "POLYGON((-122.5 37.7, -122.3 37.7, -122.3 37.8, -122.5 37.8, -122.5 37.7))";
$projects = Project::intersects('bounding_box', $geometry)->get();
```

### Instance Methods

#### toWKT
Convert spatial attribute to WKT format:
```php
$wkt = $user->toWKT('location');
// Returns: "POINT(-122.4194 37.7749)"
```

#### toGeoJSON
Convert spatial attribute to GeoJSON format:
```php
$geojson = $user->toGeoJSON('location');
// Returns: ['type' => 'Point', 'coordinates' => [-122.4194, 37.7749]]
```

#### setFromWKT
Set spatial attribute from WKT:
```php
$user->setFromWKT('location', 'POINT(-122.4194 37.7749)');
```

#### setFromCoordinates
Set spatial attribute from coordinates:
```php
$user->setFromCoordinates('location', 37.7749, -122.4194);
```

## Spatial Helper Functions

The `SpatialHelper` class provides utility functions for spatial operations:

```php
use App\Helpers\SpatialHelper;

// Convert WKT to GeoJSON
$geojson = SpatialHelper::wktToGeoJson('POINT(-122.4194 37.7749)');

// Convert GeoJSON to WKT
$wkt = SpatialHelper::geoJsonToWkt(['type' => 'Point', 'coordinates' => [-122.4194, 37.7749]]);

// Create a point
$point = SpatialHelper::makePoint(37.7749, -122.4194);

// Create a polygon
$polygon = SpatialHelper::makePolygon([
    [-122.5, 37.7],
    [-122.3, 37.7],
    [-122.3, 37.8],
    [-122.5, 37.8]
]);

// Calculate distance between two points (in meters)
$distance = SpatialHelper::distance(37.7749, -122.4194, 37.7849, -122.4094);

// Check if point is in polygon
$isInside = SpatialHelper::pointInPolygon(37.7749, -122.4194, $polygonWkt);

// Get centroid of a geometry
list($lat, $lon) = SpatialHelper::centroid($geometryWkt);

// Get bounding box of a geometry
list($minLon, $minLat, $maxLon, $maxLat) = SpatialHelper::boundingBox($geometryWkt);
```

## Factories

All models include factories with spatial data generation:

```php
use App\Models\User;
use App\Models\Organization;
use App\Models\Project;

// Create users with random locations
User::factory()->count(10)->create();

// Create organizations with projects
Organization::factory()
    ->has(Project::factory()->count(3))
    ->create();

// Create complete hierarchy
User::factory()
    ->has(
        Organization::factory()
            ->count(2)
            ->has(Project::factory()->count(3))
    )
    ->create();
```

## Database Seeder

The database seeder creates test data with spatial information:

```bash
# Run seeders
php artisan db:seed

# or via Docker
docker compose exec laravel-app php artisan db:seed
```

## Testing

Run the spatial database tests:

```bash
# Run all tests
php artisan test

# Run only spatial tests
php artisan test --filter=SpatialDatabaseTest

# or via Docker
docker compose exec laravel-app php artisan test --filter=SpatialDatabaseTest
```

## Common Spatial Queries

### Find Nearest Locations
```php
$nearestUsers = User::near('location', $latitude, $longitude)
    ->take(5)
    ->get();
```

### Find Locations Within Radius
```php
$nearbyUsers = User::withinDistance('location', $latitude, $longitude, $radiusInMeters)
    ->get();
```

### Find Projects Intersecting a Region
```php
$projects = Project::intersects('bounding_box', $regionWkt)
    ->get();
```

### Calculate Distance Between Users
```php
use App\Helpers\SpatialHelper;

$user1 = User::find(1);
$user2 = User::find(2);

$distance = SpatialHelper::distance(
    $user1->latitude,
    $user1->longitude,
    $user2->latitude,
    $user2->longitude
);
```

## Coordinate Reference Systems

- **SRID 4326**: WGS 84 (latitude/longitude)
  - Used for all spatial data in this application
  - Standard GPS coordinates

## Performance Considerations

1. **Spatial Indexes**: All spatial columns have GIST indexes for efficient queries
2. **Distance Calculations**: Use geography type for accurate distance calculations in meters
3. **Query Optimization**: Use appropriate scopes to leverage spatial indexes

## Resources

- [PostGIS Documentation](https://postgis.net/documentation/)
- [Laravel Eloquent Documentation](https://laravel.com/docs/eloquent)
- [WKT Format Specification](https://en.wikipedia.org/wiki/Well-known_text_representation_of_geometry)
- [GeoJSON Specification](https://geojson.org/)
