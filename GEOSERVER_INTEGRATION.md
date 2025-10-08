# GeoServer Integration

This Laravel application includes a comprehensive GeoServer integration service for managing workspaces, datastores, and publishing PostGIS layers to GeoServer.

## Overview

The GeoServer integration provides:
- REST API client using GuzzleHTTP
- Workspace and datastore management
- Layer publishing from PostGIS tables
- Style management with default SLD templates
- Queueable jobs with retry logic
- Automatic error handling

## Configuration

All GeoServer settings are configured in `config/geoserver.php` and can be overridden using environment variables in `.env`:

```env
# GeoServer Configuration
GEOSERVER_URL=http://geoserver:8080/geoserver
GEOSERVER_ADMIN_USER=admin
GEOSERVER_ADMIN_PASSWORD=geoserver
GEOSERVER_WORKSPACE=webgis
GEOSERVER_DATASTORE=postgis_store
GEOSERVER_TIMEOUT=30
GEOSERVER_RETRY_TIMES=3
GEOSERVER_RETRY_DELAY=1000
```

## Service Usage

### Basic Usage

```php
use App\Services\GeoServerService;

// Get service instance (automatically resolved from container)
$geoserver = app(GeoServerService::class);

// Create a workspace for an organization
$geoserver->createWorkspace('organization_workspace');

// Create a PostGIS datastore
$geoserver->createPostGISDatastore('organization_workspace', 'postgis_store');

// Publish a table as a layer
$geoserver->publishLayer(
    'organization_workspace',
    'postgis_store',
    'users',
    [
        'title' => 'Users Layer',
        'abstract' => 'User locations',
        'srs' => 'EPSG:4326'
    ]
);
```

### Publishing Layers with Jobs

For better reliability and performance, use the queueable jobs:

```php
use App\Jobs\PublishLayerToGeoServer;

// Dispatch job to publish a layer
PublishLayerToGeoServer::dispatch(
    'organization_workspace',
    'postgis_store',
    'projects',
    [
        'title' => 'Projects Layer',
        'abstract' => 'Project bounding boxes',
        'srs' => 'EPSG:4326'
    ]
);
```

### Deleting Layers

```php
use App\Jobs\DeleteLayerFromGeoServer;

// Delete a layer from GeoServer
DeleteLayerFromGeoServer::dispatch(
    'organization_workspace',
    'postgis_store',
    'old_layer_name'
);
```

### Applying Styles

```php
use App\Jobs\UpdateLayerStyle;
use App\Services\GeoServerService;

$geoserver = app(GeoServerService::class);

// Generate default point style
$sldContent = $geoserver->getDefaultPointStyle('users_style', [
    'color' => '#FF0000',
    'size' => 8
]);

// Apply style to layer
UpdateLayerStyle::dispatch(
    'organization_workspace',
    'users',
    'users_style',
    $sldContent
);

// Or use polygon style
$polygonStyle = $geoserver->getDefaultPolygonStyle('projects_style', [
    'fillColor' => '#00FF00',
    'strokeColor' => '#000000',
    'strokeWidth' => 2,
    'fillOpacity' => 0.5
]);

UpdateLayerStyle::dispatch(
    'organization_workspace',
    'projects',
    'projects_style',
    $polygonStyle
);
```

## Example: Organization-based Layer Publishing

```php
use App\Models\Organization;
use App\Jobs\PublishLayerToGeoServer;

class OrganizationController extends Controller
{
    public function publishLayers(Organization $organization)
    {
        // Create workspace for the organization
        $workspace = 'org_' . $organization->id;
        
        // Publish projects layer
        PublishLayerToGeoServer::dispatch(
            $workspace,
            config('geoserver.datastore'),
            'projects',
            [
                'title' => "{$organization->name} - Projects",
                'abstract' => "Projects for {$organization->name}",
                'srs' => 'EPSG:4326'
            ]
        );
        
        return response()->json([
            'message' => 'Layer publishing initiated',
            'workspace' => $workspace
        ]);
    }
}
```

## Testing GeoServer Connection

Use the built-in test command to verify your GeoServer integration:

```bash
# Test with default options
php artisan geoserver:test

# Test with custom workspace and table
php artisan geoserver:test --workspace=my_workspace --table=projects
```

This command will:
1. Create a test workspace
2. Create a PostGIS datastore
3. Publish a sample layer
4. Create and apply a default style
5. Provide a link to view the layer in GeoServer

## Job Configuration

All jobs include automatic retry logic:

### PublishLayerToGeoServer
- **Retries**: 3 attempts
- **Timeout**: 120 seconds
- **Backoff**: 10 seconds between retries

### DeleteLayerFromGeoServer
- **Retries**: 3 attempts
- **Timeout**: 60 seconds
- **Backoff**: 5 seconds between retries

### UpdateLayerStyle
- **Retries**: 5 attempts (more retries for style updates)
- **Timeout**: 60 seconds
- **Backoff**: 10 seconds between retries

## Error Handling

The service uses custom `GeoServerException` for error handling:

```php
use App\Exceptions\GeoServerException;

try {
    $geoserver->publishLayer('workspace', 'datastore', 'table_name');
} catch (GeoServerException $e) {
    Log::error('GeoServer error: ' . $e->getMessage());
    // Handle the error
}
```

Available exception factory methods:
- `GeoServerException::workspaceCreationFailed($workspace)`
- `GeoServerException::datastoreCreationFailed($datastore)`
- `GeoServerException::layerPublishFailed($layer)`
- `GeoServerException::layerDeletionFailed($layer)`
- `GeoServerException::styleUpdateFailed($style)`
- `GeoServerException::connectionFailed()`

## Advanced Features

### Custom Connection Parameters

You can provide custom PostGIS connection parameters:

```php
$geoserver->createPostGISDatastore('workspace', 'custom_datastore', [
    'host' => 'custom-postgis',
    'port' => '5432',
    'database' => 'custom_db',
    'schema' => 'public',
    'user' => 'custom_user',
    'password' => 'custom_password'
]);
```

### Custom SLD Styles

Create your own SLD styles and apply them:

```php
$customSld = <<<SLD
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0" xmlns="http://www.opengis.net/sld">
  <NamedLayer>
    <Name>custom_style</Name>
    <UserStyle>
      <!-- Your custom SLD content -->
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
SLD;

$geoserver->createOrUpdateStyle('workspace', 'custom_style', $customSld);
$geoserver->applyStyleToLayer('workspace', 'layer_name', 'custom_style');
```

### Checking Resource Existence

```php
// Check if workspace exists
if ($geoserver->workspaceExists('my_workspace')) {
    // Workspace exists
}

// Check if datastore exists
if ($geoserver->datastoreExists('my_workspace', 'my_datastore')) {
    // Datastore exists
}

// Check if layer exists
if ($geoserver->layerExists('my_workspace', 'my_layer')) {
    // Layer exists
}
```

## Queue Configuration

Make sure your queue is configured and running:

```bash
# Run queue worker
php artisan queue:work

# Or use in Docker
docker compose exec laravel-app php artisan queue:work
```

For production, consider using a supervisor or systemd service to keep the queue worker running.

## Viewing Published Layers

Once a layer is published, you can view it in:

1. **GeoServer Admin Interface**: 
   - http://localhost:8080/geoserver
   - Navigate to "Layers" to see published layers

2. **Layer Preview**:
   - http://localhost:8080/geoserver/web/?wicket:bookmarkablePage=:org.geoserver.web.data.layer.LayerPage
   - Click on "OpenLayers" next to your layer

3. **WMS URL**:
   ```
   http://localhost:8080/geoserver/{workspace}/wms?
     service=WMS&
     version=1.1.0&
     request=GetMap&
     layers={workspace}:{layer}&
     bbox={minx},{miny},{maxx},{maxy}&
     width=800&
     height=600&
     srs=EPSG:4326&
     format=image/png
   ```

## Best Practices

1. **Use Queues**: Always use jobs for layer operations to avoid timeouts
2. **Workspace Per Organization**: Create separate workspaces for each organization
3. **Retry Logic**: Jobs have built-in retry logic - don't disable it
4. **Error Logging**: All operations are logged - check logs for debugging
5. **Test First**: Use `php artisan geoserver:test` to verify connectivity before integrating

## Troubleshooting

### Connection Issues
- Verify GeoServer is running: `docker compose ps geoserver`
- Check GeoServer URL in `.env`
- Verify credentials are correct

### Layer Not Appearing
- Check if table exists in PostGIS
- Verify table has a geometry column
- Check GeoServer logs: `docker compose logs geoserver`

### Style Not Applied
- Verify SLD syntax is correct
- Check if style exists in GeoServer admin
- Ensure layer and style are in the same workspace

## Resources

- [GeoServer REST API Documentation](https://docs.geoserver.org/latest/en/user/rest/index.html)
- [SLD Cookbook](https://docs.geoserver.org/latest/en/user/styling/sld/cookbook/index.html)
- [Laravel Queues](https://laravel.com/docs/queues)
