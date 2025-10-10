# Testing Guide

Complete guide for testing the Laravel WebGIS application.

## Table of Contents

1. [Testing Overview](#testing-overview)
2. [Setting Up Test Environment](#setting-up-test-environment)
3. [Running Tests](#running-tests)
4. [Unit Tests](#unit-tests)
5. [Feature Tests](#feature-tests)
6. [Browser Tests](#browser-tests)
7. [Test Helpers](#test-helpers)
8. [Writing New Tests](#writing-new-tests)
9. [Code Coverage](#code-coverage)
10. [Continuous Integration](#continuous-integration)

## Testing Overview

### Test Types

1. **Unit Tests**: Test individual classes and methods
2. **Feature Tests**: Test HTTP endpoints and workflows
3. **Browser Tests**: Test UI interactions (future enhancement)

### Test Database

Tests use SQLite in-memory database by default (configured in `phpunit.xml`).

### Test Structure

```
tests/
├── Feature/              # Feature tests
│   ├── AnalysisTest.php
│   ├── AuthenticationTest.php
│   ├── AuthorizationTest.php
│   ├── DataImportTest.php
│   ├── ExportTest.php
│   ├── GeoServerJobsTest.php
│   ├── LayerTest.php
│   ├── OrganizationDataIsolationTest.php
│   ├── ProjectControllerTest.php
│   └── SpatialDatabaseTest.php
├── Unit/                 # Unit tests
│   ├── GeoServerServiceTest.php
│   └── SpatialHelperTest.php
├── TestHelpers/          # Test helper classes
│   ├── MockGeoServerResponse.php
│   └── SpatialDataHelper.php
└── TestCase.php         # Base test case
```

## Setting Up Test Environment

### Install Dependencies

```bash
composer install
npm install
```

### Configure Test Environment

Test configuration is in `phpunit.xml`:

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### Create Test Database (if using PostgreSQL)

```bash
# Create test database
docker compose exec postgis psql -U postgres -c "CREATE DATABASE laravel_gis_test;"

# Enable PostGIS
docker compose exec postgis psql -U postgres -d laravel_gis_test -c "CREATE EXTENSION postgis;"
```

Update `phpunit.xml` to use PostgreSQL:
```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="laravel_gis_test"/>
```

## Running Tests

### All Tests

```bash
# Run all tests
php artisan test

# With Docker
docker compose exec laravel-app php artisan test
```

### Specific Test Suites

```bash
# Run only unit tests
php artisan test --testsuite=Unit

# Run only feature tests
php artisan test --testsuite=Feature
```

### Specific Test Files

```bash
# Run specific test file
php artisan test tests/Feature/AnalysisTest.php

# Run specific test method
php artisan test --filter=test_can_perform_buffer_analysis
```

### With Options

```bash
# Verbose output
php artisan test --verbose

# Stop on first failure
php artisan test --stop-on-failure

# Parallel testing
php artisan test --parallel

# With coverage (requires Xdebug)
php artisan test --coverage
```

## Unit Tests

Unit tests focus on testing individual classes and methods in isolation.

### Example: Spatial Helper Test

```php
public function test_can_create_point()
{
    $point = SpatialHelper::makePoint(37.7749, -122.4194);
    
    $this->assertEquals('POINT(-122.4194 37.7749)', $point);
}
```

### Running Unit Tests

```bash
php artisan test --testsuite=Unit
```

### Current Unit Tests

- **SpatialHelperTest**: Tests spatial utility functions
  - Point creation
  - Linestring creation
  - Polygon creation
  - Distance calculations
  - Buffer operations
  - Spatial relationships

- **GeoServerServiceTest**: Tests GeoServer integration
  - Workspace creation
  - Datastore management
  - Layer publishing
  - Style application

## Feature Tests

Feature tests simulate HTTP requests and test application workflows.

### Example: Analysis Test

```php
public function test_can_perform_buffer_analysis()
{
    $response = $this->actingAs($this->user)
        ->postJson('/api/analysis/buffer', [
            'geometry' => 'POINT(0 0)',
            'distance' => 1000,
            'unit' => 'meters',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'result']);
}
```

### Running Feature Tests

```bash
php artisan test --testsuite=Feature
```

### Current Feature Tests

1. **AnalysisTest**: Spatial analysis operations
   - Buffer analysis
   - Spatial queries
   - Attribute queries
   - Distance measurements
   - Area calculations

2. **AuthenticationTest**: User authentication
   - Login
   - Registration
   - Password reset
   - Session management

3. **AuthorizationTest**: Access control
   - Role-based permissions
   - Resource ownership
   - Organization isolation

4. **DataImportTest**: File upload and processing
   - Shapefile import
   - GeoJSON import
   - CSV import
   - Error handling

5. **ExportTest**: Data export
   - GeoJSON export
   - CSV export
   - Map configuration export

6. **GeoServerJobsTest**: GeoServer queue jobs
   - Layer publishing
   - Layer deletion
   - Style updates

7. **LayerTest**: Layer management
   - CRUD operations
   - Publishing
   - Styling

8. **OrganizationDataIsolationTest**: Data security
   - Cross-organization access prevention
   - Data scoping

9. **ProjectControllerTest**: Project management
   - CRUD operations
   - Collaboration
   - Comments

10. **SpatialDatabaseTest**: Database operations
    - Spatial queries
    - PostGIS functions
    - Data integrity

## Browser Tests

Browser tests (using Laravel Dusk) test UI interactions. These are planned for future implementation.

### Installing Dusk

```bash
composer require --dev laravel/dusk
php artisan dusk:install
```

### Example Browser Test

```php
public function test_user_can_create_map()
{
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->user)
            ->visit('/maps/builder')
            ->type('name', 'Test Map')
            ->press('Create')
            ->assertPathIs('/maps/builder/1')
            ->assertSee('Test Map');
    });
}
```

### Running Browser Tests

```bash
php artisan dusk
```

## Test Helpers

### SpatialDataHelper

Utility class for generating test spatial data.

```php
use Tests\TestHelpers\SpatialDataHelper;

// Create geometries
$point = SpatialDataHelper::createPoint(-122.4194, 37.7749);
$polygon = SpatialDataHelper::createPolygon([
    [-122.5, 37.7],
    [-122.4, 37.7],
    [-122.4, 37.8],
    [-122.5, 37.8],
]);

// Random coordinates
$coords = SpatialDataHelper::randomCoordinates(-180, 180, -90, 90);

// Bounding box
$bbox = SpatialDataHelper::createBoundingBox(-122.4194, 37.7749, 0.1);

// GeoJSON features
$feature = SpatialDataHelper::createGeoJsonFeature($point, [
    'name' => 'San Francisco',
    'population' => 884363,
]);

// Sample data
$data = SpatialDataHelper::getSampleShapefileData();
```

### MockGeoServerResponse

Mock HTTP responses for GeoServer API testing.

```php
use Tests\TestHelpers\MockGeoServerResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

// Create mock responses
$mock = new MockHandler([
    MockGeoServerResponse::workspaceCreated('test'),
    MockGeoServerResponse::datastoreCreated('test_ds'),
    MockGeoServerResponse::layerPublished('test_layer'),
]);

$handlerStack = HandlerStack::create($mock);
$client = new Client(['handler' => $handlerStack]);
```

### Using Test Helpers

```php
use Tests\TestHelpers\SpatialDataHelper;
use Tests\TestHelpers\MockGeoServerResponse;

class MyTest extends TestCase
{
    public function test_spatial_operation()
    {
        // Generate test data
        $point = SpatialDataHelper::createPoint(-122.4, 37.7);
        $polygon = SpatialDataHelper::createBoundingBox(-122.4, 37.7, 0.1);
        
        // Test spatial relationship
        $intersects = DB::select("
            SELECT ST_Intersects(
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326)
            ) as result
        ", [$point, $polygon]);
        
        $this->assertTrue($intersects[0]->result);
    }
}
```

## Writing New Tests

### Test Structure

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup test data
        $this->user = User::factory()->create();
    }

    public function test_feature_works()
    {
        // Arrange
        $data = ['key' => 'value'];
        
        // Act
        $response = $this->actingAs($this->user)
            ->postJson('/api/endpoint', $data);
        
        // Assert
        $response->assertStatus(200);
    }
}
```

### Best Practices

1. **Use RefreshDatabase**: Ensures clean database state
2. **Test One Thing**: Each test should verify one behavior
3. **Arrange-Act-Assert**: Clear test structure
4. **Descriptive Names**: Use clear test method names
5. **Factory Usage**: Use factories for test data
6. **Mock External Services**: Don't rely on external APIs

### Naming Conventions

```php
// Feature tests
test_user_can_create_layer()
test_unauthorized_user_cannot_delete_project()
test_validation_fails_with_invalid_data()

// Unit tests
test_calculates_distance_correctly()
test_converts_wkt_to_geojson()
test_throws_exception_for_invalid_geometry()
```

### Testing API Endpoints

```php
public function test_api_endpoint()
{
    $response = $this->actingAs($this->user)
        ->postJson('/api/layers', [
            'name' => 'Test Layer',
            'table_name' => 'test_table',
        ]);

    $response
        ->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'table_name',
                'created_at',
            ]
        ]);
    
    $this->assertDatabaseHas('layers', [
        'name' => 'Test Layer',
    ]);
}
```

### Testing Validation

```php
public function test_validation_fails_with_missing_data()
{
    $response = $this->actingAs($this->user)
        ->postJson('/api/layers', []);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'table_name']);
}
```

### Testing Authorization

```php
public function test_user_cannot_access_other_organization_data()
{
    $otherOrg = Organization::factory()->create();
    $otherLayer = Layer::factory()->create([
        'organization_id' => $otherOrg->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/layers/{$otherLayer->id}");

    $response->assertStatus(403);
}
```

## Code Coverage

### Enable Coverage

Requires Xdebug or PCOV extension.

```bash
# Install Xdebug
pecl install xdebug

# Enable in php.ini
zend_extension=xdebug.so
xdebug.mode=coverage
```

### Generate Coverage Report

```bash
# HTML report
php artisan test --coverage-html coverage

# Open in browser
open coverage/index.html

# Text report
php artisan test --coverage-text

# Minimum coverage threshold
php artisan test --coverage --min=80
```

### Coverage Configuration

In `phpunit.xml`:

```xml
<coverage>
    <include>
        <directory suffix=".php">./app</directory>
    </include>
    <exclude>
        <directory>./app/Console</directory>
        <file>./app/Exceptions/Handler.php</file>
    </exclude>
</coverage>
```

## Continuous Integration

### GitHub Actions

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest
    
    services:
      postgres:
        image: postgis/postgis:13-3.1
        env:
          POSTGRES_DB: laravel_gis_test
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
        ports:
          - 5432:5432
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
          extensions: pdo, pgsql, mbstring, zip
          coverage: xdebug
      
      - name: Install Dependencies
        run: composer install --no-interaction
      
      - name: Run Tests
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_PORT: 5432
          DB_DATABASE: laravel_gis_test
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: php artisan test --coverage
```

### Pre-commit Hook

Create `.git/hooks/pre-commit`:

```bash
#!/bin/bash

# Run tests before commit
php artisan test

if [ $? -ne 0 ]; then
    echo "Tests failed. Commit aborted."
    exit 1
fi
```

Make executable:
```bash
chmod +x .git/hooks/pre-commit
```

## Troubleshooting

### Tests Failing

```bash
# Clear caches
php artisan config:clear
php artisan cache:clear

# Refresh database
php artisan migrate:fresh

# Check environment
cat phpunit.xml
```

### Memory Exhausted

Increase memory limit in `phpunit.xml`:

```xml
<php>
    <ini name="memory_limit" value="512M"/>
</php>
```

### Slow Tests

```bash
# Identify slow tests
php artisan test --profile

# Run in parallel
php artisan test --parallel
```

### Database Issues

```bash
# Verify database connection
php artisan tinker
>>> DB::connection()->getPdo();

# Check migrations
php artisan migrate:status
```

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Laravel Testing Guide](https://laravel.com/docs/testing)
- [Laravel Dusk](https://laravel.com/docs/dusk)
- [Pest PHP](https://pestphp.com/) (alternative testing framework)
