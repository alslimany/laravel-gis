# Data Import System

This Laravel application includes a comprehensive spatial data import system that allows users to upload and process various spatial file formats and import them into PostGIS.

## Overview

The data import system provides:
- Support for multiple spatial file formats (Shapefile, GeoJSON, KML, CSV)
- Drag-and-drop file upload interface
- Background processing using Laravel queues
- Progress tracking and status monitoring
- Automatic table creation in PostGIS
- GDAL/OGR integration for robust spatial data handling
- Error handling and user notifications

## Supported File Formats

### Shapefile (.shp)
- Must include companion files: .shx, .dbf, .prj (recommended)
- Upload the .shp file and select additional files when prompted
- Automatically handles coordinate system transformation

### GeoJSON (.geojson, .json)
- Standard GeoJSON format
- Single file upload
- Supports all geometry types

### KML (.kml, .kmz)
- Google Earth format
- Single file upload
- Automatically extracts and processes

### CSV (.csv)
- Must contain coordinate columns
- Auto-detects common column names: longitude/lon/x, latitude/lat/y
- Converts to spatial points

## Configuration

All data import settings are configured in `config/dataimport.php`:

```php
return [
    'ogr2ogr_path' => env('OGR2OGR_PATH', '/usr/bin/ogr2ogr'),
    'ogrinfo_path' => env('OGRINFO_PATH', '/usr/bin/ogrinfo'),
    'upload_disk' => env('DATA_IMPORT_DISK', 'local'),
    'max_file_size' => env('DATA_IMPORT_MAX_FILE_SIZE', 104857600), // 100MB
    'table_prefix' => env('DATA_IMPORT_TABLE_PREFIX', 'import_'),
    'default_srid' => env('DATA_IMPORT_DEFAULT_SRID', 4326),
    'timeout' => env('DATA_IMPORT_TIMEOUT', 300), // 5 minutes
];
```

### Environment Variables

Add to your `.env` file:

```env
# Data Import Configuration
OGR2OGR_PATH=/usr/bin/ogr2ogr
OGRINFO_PATH=/usr/bin/ogrinfo
DATA_IMPORT_DISK=local
DATA_IMPORT_MAX_FILE_SIZE=104857600
DATA_IMPORT_TABLE_PREFIX=import_
DATA_IMPORT_DEFAULT_SRID=4326
DATA_IMPORT_TIMEOUT=300
```

## Usage

### Web Interface

1. **Access Import Page**
   - Navigate to "Data Imports" in the navigation menu
   - Click "New Import" to upload files

2. **Upload Files**
   - Drag and drop files or click "Browse Files"
   - For Shapefiles, select all companion files (.shx, .dbf, .prj)
   - Click "Upload File"

3. **Monitor Progress**
   - View import status in the imports list
   - Click on an import to see detailed progress
   - Status updates automatically via AJAX

4. **Use Imported Data**
   - Once completed, data is available in a PostGIS table
   - Table name is displayed in the import details
   - Can be published to GeoServer for visualization

### Programmatic Usage

```php
use App\Jobs\ProcessGeoJSONJob;
use App\Models\DataImport;

// Create import record
$import = DataImport::create([
    'user_id' => auth()->id(),
    'organization_id' => auth()->user()->organization_id,
    'file_name' => 'data.geojson',
    'file_path' => 'imports/data.geojson',
    'file_type' => 'geojson',
    'file_size' => 1024000,
    'status' => 'pending',
]);

// Dispatch processing job
ProcessGeoJSONJob::dispatch($import->id);
```

## Processing Jobs

### ProcessShapefileJob
- Handles Shapefile imports
- Processes multiple files (.shp, .shx, .dbf, .prj)
- Retries: 3 attempts
- Timeout: 10 minutes

### ProcessGeoJSONJob
- Handles GeoJSON imports
- Single file processing
- Retries: 3 attempts
- Timeout: 10 minutes

### ProcessKMLJob
- Handles KML/KMZ imports
- Automatic KMZ extraction
- Retries: 3 attempts
- Timeout: 10 minutes

## Database Schema

The `data_imports` table tracks all import operations:

```sql
CREATE TABLE data_imports (
    id BIGINT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    organization_id BIGINT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    status VARCHAR(255) DEFAULT 'pending',
    table_name VARCHAR(255) NULL,
    geometry_type VARCHAR(255) NULL,
    feature_count INTEGER NULL,
    progress INTEGER DEFAULT 0,
    error_message TEXT NULL,
    metadata JSON NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Import Statuses

- **pending**: Import queued, waiting to be processed
- **processing**: Currently being imported to PostGIS
- **completed**: Successfully imported
- **failed**: Import failed (see error_message)

## GDAL/OGR Integration

The system uses GDAL's `ogr2ogr` command-line tool for importing spatial data:

### Requirements

- GDAL/OGR installed on the server
- `ogr2ogr` available in system PATH
- PostGIS extension enabled in PostgreSQL

### Installation (Docker)

If using Docker, ensure GDAL is installed in your container:

```dockerfile
RUN apt-get update && apt-get install -y \
    gdal-bin \
    libgdal-dev
```

### Manual Installation

Ubuntu/Debian:
```bash
sudo apt-get install gdal-bin
```

macOS:
```bash
brew install gdal
```

## Service Methods

### DataImportService

```php
// Get file information
$info = $importService->getFileInfo($filePath, 'geojson');
// Returns: ['geometry_type', 'feature_count', 'srid', 'bbox']

// Import to PostGIS
$importService->importToPostGIS($filePath, $tableName, 'geojson', 4326);

// Get table information
$geometryType = $importService->getTableGeometryType($tableName);
$featureCount = $importService->getTableFeatureCount($tableName);

// Generate unique table name
$tableName = $importService->generateTableName($fileName, $organizationId);

// Delete imported table
$importService->deleteImportedTable($tableName);

// Check if OGR is available
$isAvailable = $importService->isOgrAvailable();
```

## Authorization

Access to import functionality is controlled by the `DataImportPolicy`:

- Users can only view/delete their own imports
- Users from the same organization can view each other's imports
- Imports require an organization membership

## Queue Configuration

Data imports are processed using Laravel queues. Ensure queue workers are running:

```bash
php artisan queue:work
```

For production, use a process manager like Supervisor:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --tries=3 --timeout=600
autostart=true
autorestart=true
numprocs=2
```

## Error Handling

Common errors and solutions:

### "ogr2ogr command not found"
- Install GDAL on your server
- Verify `OGR2OGR_PATH` in config

### "Failed to connect to PostGIS"
- Check database connection settings
- Ensure PostGIS extension is enabled

### "File too large"
- Increase `DATA_IMPORT_MAX_FILE_SIZE` in config
- Adjust PHP `upload_max_filesize` and `post_max_size`

### "Invalid geometry"
- Check source file for corrupt geometries
- Use QGIS or similar tools to validate/repair

## Integration with GeoServer

After importing data, you can publish layers to GeoServer:

```php
use App\Jobs\PublishLayerToGeoServer;

$import = DataImport::find($importId);

if ($import->isCompleted()) {
    PublishLayerToGeoServer::dispatch(
        'workspace',
        'datastore',
        $import->table_name,
        [
            'title' => $import->file_name,
            'abstract' => "Imported from {$import->file_name}",
            'srs' => 'EPSG:4326',
        ]
    );
}
```

## API Endpoints

- `GET /imports` - List all imports
- `GET /imports/create` - Show upload form
- `POST /imports` - Upload and process file
- `GET /imports/{import}` - View import details
- `GET /imports/{import}/status` - Get import status (JSON)
- `DELETE /imports/{import}` - Delete import

## Best Practices

1. **Large Files**: Always use queue workers for files > 10MB
2. **Coordinate Systems**: Include .prj files with Shapefiles
3. **File Organization**: Keep uploaded files in separate storage
4. **Progress Monitoring**: Use AJAX polling for status updates
5. **Error Logging**: Monitor logs for import failures
6. **Cleanup**: Regularly clean up old import records and files

## Testing

Run the test suite:

```bash
php artisan test --filter=DataImport
```

## Troubleshooting

### Import stuck in "processing" status
- Check queue workers are running
- Review Laravel logs for errors
- Verify GDAL is working: `ogr2ogr --version`

### Files not uploading
- Check storage permissions
- Verify disk configuration in `config/filesystems.php`
- Check PHP upload limits

### Table not created
- Verify PostGIS is enabled: `SELECT PostGIS_Version();`
- Check database user permissions
- Review ogr2ogr error output in logs

## Resources

- [GDAL/OGR Documentation](https://gdal.org/programs/ogr2ogr.html)
- [PostGIS Documentation](https://postgis.net/documentation/)
- [Laravel Queues](https://laravel.com/docs/queues)
- [Spatial File Formats](https://en.wikipedia.org/wiki/GIS_file_formats)
