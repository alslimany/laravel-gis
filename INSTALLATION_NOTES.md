# Installation Notes for Data Import System

## Prerequisites

The data import system requires GDAL/OGR to be installed on the server or in your Docker container.

### Docker Installation (Recommended)

Add to your Dockerfile:

```dockerfile
RUN apt-get update && apt-get install -y \
    gdal-bin \
    libgdal-dev \
    && rm -rf /var/lib/apt/lists/*
```

Or update your `docker-compose.yml` to use an image with GDAL pre-installed:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    # ... other settings
```

### Manual Installation

#### Ubuntu/Debian
```bash
sudo apt-get update
sudo apt-get install -y gdal-bin
```

#### macOS
```bash
brew install gdal
```

#### CentOS/RHEL
```bash
sudo yum install gdal gdal-devel
```

### Verify Installation

After installation, verify GDAL is available:

```bash
ogr2ogr --version
ogrinfo --version
```

Expected output:
```
GDAL 3.x.x, released 20xx/xx/xx
```

## Database Setup

Run migrations to create the data_imports table:

```bash
php artisan migrate
```

## Queue Worker Setup

The data import system uses Laravel queues to process files in the background. You need to run queue workers:

### Development
```bash
php artisan queue:work
```

### Production (with Supervisor)

Create a supervisor configuration file `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan queue:work --tries=3 --timeout=600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/worker.log
stopwaitsecs=3600
```

Then start the worker:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

## Storage Setup

Create the imports directory:

```bash
mkdir -p storage/app/imports
chmod -R 775 storage/app/imports
```

## Configuration

Update your `.env` file:

```env
# Data Import Configuration
OGR2OGR_PATH=/usr/bin/ogr2ogr
OGRINFO_PATH=/usr/bin/ogrinfo
DATA_IMPORT_DISK=local
DATA_IMPORT_MAX_FILE_SIZE=104857600
DATA_IMPORT_TABLE_PREFIX=import_
DATA_IMPORT_DEFAULT_SRID=4326
DATA_IMPORT_TIMEOUT=300

# Queue Configuration
QUEUE_CONNECTION=database
```

## Testing the Installation

1. Log in to the application
2. Navigate to "Data Imports"
3. Upload a sample GeoJSON file
4. Monitor the import progress
5. Verify the table was created in PostGIS

Sample GeoJSON file for testing (save as `test.geojson`):

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "properties": {
        "name": "Test Location"
      },
      "geometry": {
        "type": "Point",
        "coordinates": [-122.4194, 37.7749]
      }
    }
  ]
}
```

## Troubleshooting

### ogr2ogr not found
- Verify GDAL is installed: `which ogr2ogr`
- Check the path in config/dataimport.php
- Update OGR2OGR_PATH in .env if needed

### Queue jobs not processing
- Ensure queue workers are running: `php artisan queue:work`
- Check logs: `tail -f storage/logs/laravel.log`
- Verify QUEUE_CONNECTION is set correctly

### Permission denied errors
- Check storage permissions: `chmod -R 775 storage`
- Ensure web server user owns storage directory

### Database connection errors
- Verify PostGIS is enabled: `SELECT PostGIS_Version();`
- Check database credentials in .env
- Ensure PostgreSQL is running

## Next Steps

After successful installation:

1. Review the [DATA_IMPORT.md](DATA_IMPORT.md) documentation
2. Configure GeoServer integration if needed (see [GEOSERVER_INTEGRATION.md](GEOSERVER_INTEGRATION.md))
3. Set up regular backups of the database
4. Configure monitoring for queue workers
5. Set up cleanup jobs for old imports
