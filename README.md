# Laravel WebGIS Project

A Laravel-based WebGIS application with Docker infrastructure including PostGIS, GeoServer, Redis, and Nginx.

## Current stack

`composer.json` and `package.json` are the source of truth. If this README disagrees with those files, trust the manifests.

| Piece | Constraint | Manifest |
| --- | --- | --- |
| PHP | `^8.3` (app image is PHP 8.4-FPM in `Dockerfile`) | `composer.json` |
| Laravel | `laravel/framework` `^13.0` | `composer.json` |
| Inertia (server) | `inertiajs/inertia-laravel` | `composer.json` |
| React | `^19` | `package.json` |
| Inertia (client) | `@inertiajs/react` | `package.json` |
| OpenLayers | `ol` `^10` | `package.json` |

The UI is React with Inertia and OpenLayers.

## Features

- **Laravel 13** on PHP 8.3+, with a React 19, Inertia, and OpenLayers 10 frontend
- **Authentication & Authorization** - Complete role-based access control system with organization isolation
- **PostGIS 13-3.1** - Spatial database extension for PostgreSQL with full spatial database support
- **Spatial Models** - User, Organization, and Project models with spatial capabilities
- **Spatial Queries** - withinDistance, near, intersects, and withinPolygon scopes
- **Helper Functions** - WKT/GeoJSON conversion, distance calculations, and more
- **GeoServer 2.21.x** - Open source server for sharing geospatial data with CORS enabled
- **Redis** - In-memory data structure store for caching and queues
- **Nginx** - High-performance web server
- **Docker Compose** - Multi-container orchestration

### Production Ready ✨ NEW
- ✅ **Production Docker Setup**: Optimized containers with health checks
- ✅ **SSL/HTTPS Support**: Nginx configuration with Let's Encrypt
- ✅ **Automated Backups**: Daily PostgreSQL backups with retention
- ✅ **Health Monitoring**: Endpoints for app, database, cache, and queue status
- ✅ **Enhanced Logging**: Specialized channels for GIS, performance, and imports
- ✅ **Test Infrastructure**: 132 tests with spatial test helpers
- ✅ **Comprehensive Documentation**: 70,000+ words covering API, deployment, testing, and monitoring

See [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) for production deployment and [TESTING_GUIDE.md](TESTING_GUIDE.md) for testing documentation.

## Key Features

### GIS Tools & Analysis ✨ NEW
- ✅ **Measurement Tools**: Distance, area, and coordinate display
- ✅ **Drawing Tools**: Point, line, and polygon drawing
- ✅ **Spatial Analysis**: Buffer analysis, spatial queries (within, contains, intersects)
- ✅ **Attribute Queries**: Advanced query builder with multiple conditions
- ✅ **Export Tools**: GeoJSON, CSV, JSON, and PNG export
- ✅ **Analysis API**: 6 endpoints for spatial operations
- ✅ **Export API**: 5 endpoints for data export
- ✅ **UI Components**: Analysis panel with intuitive interface
- ✅ **Comprehensive Tests**: 29 tests covering all features

See [GIS_TOOLS_DOCUMENTATION.md](GIS_TOOLS_DOCUMENTATION.md) for detailed documentation and [GIS_TOOLS_QUICK_REFERENCE.md](GIS_TOOLS_QUICK_REFERENCE.md) for quick start guide.

### Authentication & Authorization
- ✅ User registration and login with Laravel UI
- ✅ Role-based access control (Admin, Editor, Viewer)
- ✅ Organization-based data isolation
- ✅ Policy-driven authorization for Projects and Organizations
- ✅ Protected routes with middleware
- ✅ Comprehensive test coverage (27 tests)

See [AUTH_GUIDE.md](AUTH_GUIDE.md) for detailed documentation.

### Spatial Database
- ✅ PostGIS extension enabled
- ✅ Users table with location (GEOGRAPHY POINT)
- ✅ Projects table with bounding_box (GEOMETRY POLYGON)
- ✅ Spatial indexes for efficient queries
- ✅ Eloquent models with spatial traits
- ✅ Helper functions for WKT/GeoJSON conversion
- ✅ Factory support for spatial test data

See [SPATIAL_FEATURES.md](SPATIAL_FEATURES.md) for detailed documentation.

### GeoServer Integration
- ✅ Complete REST API client for GeoServer
- ✅ Workspace and datastore management
- ✅ Layer publishing from PostGIS tables
- ✅ Style management with default SLD templates
- ✅ Queueable jobs with retry logic
- ✅ Automatic error handling and logging

See [GEOSERVER_INTEGRATION.md](GEOSERVER_INTEGRATION.md) for detailed documentation.

### Data Import System
- ✅ Multi-format support (Shapefile, GeoJSON, KML, CSV)
- ✅ Drag-and-drop file upload interface
- ✅ Background processing with Laravel queues
- ✅ GDAL/OGR integration for robust spatial data handling
- ✅ Dynamic PostGIS table creation
- ✅ Real-time progress tracking
- ✅ Error handling and user notifications
- ✅ Organization-based data isolation

See [DATA_IMPORT.md](DATA_IMPORT.md) for detailed documentation and [INSTALLATION_NOTES.md](INSTALLATION_NOTES.md) for setup instructions.

## Prerequisites

- Docker (20.10 or higher)
- Docker Compose (1.29 or higher)
- Git

## Quick Start

### 1. Clone the repository

```bash
git clone https://github.com/alslimany/laravel-gis.git
cd laravel-gis
```

### 2. Quick Installation

Use the Makefile for a complete installation:

```bash
make install
```

This command will:
- Build Docker images
- Start all containers
- Install Composer dependencies
- Copy `.env.example` to `.env`
- Generate application key
- Run database migrations

### 3. Seed the Database

```bash
make seed
```

This creates:
- Default roles (admin, editor, viewer)
- A **local demo** admin user
- Sample organization with projects

**Change every demo credential before any network exposure. Never use them on a public host.**

### 4. Access the Application

- **Laravel Application**: http://localhost
  - Dashboard: http://localhost/dashboard
- **GeoServer Admin**: http://localhost:8080/geoserver
- **PostgreSQL/PostGIS**: localhost:5432 (database `laravel_gis`)

Sign-in values for a stock local install are under [Local demo credentials (localhost only)](#local-demo-credentials-localhost-only).

### Local demo credentials (localhost only)

**Local demo only.** These are the stock localhost logins. **Change them before any network exposure. Never use them on a public host.** If any of them was ever used outside localhost, rotate it.

| Service | Username | Password |
| --- | --- | --- |
| App | `test@example.com` | `password` |
| GeoServer | `admin` | `geoserver` |
| PostgreSQL | `postgres` | `secret` |

Set your own values in `.env` (see [Environment Configuration](#environment-configuration)). Do not copy this table into production.

## Manual Installation

If you prefer to set up manually:

### 1. Build Docker images

```bash
make build
# or
docker compose build
```

### 2. Start containers

```bash
make up
# or
docker compose up -d
```

### 3. Install dependencies

```bash
make composer
# or
docker compose exec laravel-app composer install
```

### 4. Configure environment

```bash
docker compose exec laravel-app cp .env.example .env
docker compose exec laravel-app php artisan key:generate
```

### 5. Run migrations

```bash
make migrate
# or
docker compose exec laravel-app php artisan migrate
```

## Available Make Commands

```bash
make help           # Show all available commands
make build          # Build Docker images
make up             # Start all containers
make down           # Stop all containers
make restart        # Restart all containers
make logs           # View container logs
make shell          # Access Laravel app shell
make composer       # Run composer install
make artisan        # Run artisan commands
make migrate        # Run database migrations
make migrate-fresh  # Fresh database with migrations
make seed           # Seed the database
make test           # Run tests
make clean          # Remove all containers and volumes
make install        # Complete installation
```

## Docker Services

### Laravel App
- **Container**: `laravel-app`
- **Image**: Custom (PHP 8.4-FPM; `composer.json` requires PHP `^8.3`)
- **Extensions**: PDO PostgreSQL, GD, ZIP, Redis
- **Port**: 9000 (internal)

### Nginx
- **Container**: `nginx-webserver`
- **Image**: nginx:alpine
- **Ports**: 80, 443

### PostGIS
- **Container**: `postgis-db`
- **Image**: postgis/postgis:13-3.1
- **Port**: 5432
- **Volume**: `postgis-data`

### GeoServer
- **Container**: `geoserver`
- **Image**: kartoza/geoserver:2.21.0
- **Port**: 8080
- **Volume**: `geoserver-data`
- **Features**: CORS enabled

### Redis
- **Container**: `redis-cache`
- **Image**: redis:alpine
- **Port**: 6379
- **Volume**: `redis-data`

## Environment Configuration

Key environment variables in `.env`. **Local demo only** until you replace each `CHANGE_ME`. **Change them before any network exposure. Never use them on a public host.** A stock local install may still fall back to the values in [Local demo credentials (localhost only)](#local-demo-credentials-localhost-only) until `.env` overrides them.

```env
# Database — replace CHANGE_ME before start
DB_CONNECTION=pgsql
DB_HOST=postgis
DB_PORT=5432
DB_DATABASE=laravel_gis
DB_USERNAME=postgres
DB_PASSWORD=CHANGE_ME

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# GeoServer — replace CHANGE_ME; never publish the local demo password
GEOSERVER_URL=http://geoserver:8080/geoserver
GEOSERVER_ADMIN_USER=admin
GEOSERVER_ADMIN_PASSWORD=CHANGE_ME
GEOSERVER_WORKSPACE=webgis
GEOSERVER_DATASTORE=postgis_store
```

## Common Tasks

### Running Artisan Commands

```bash
make artisan CMD="migrate:status"
# or
docker compose exec laravel-app php artisan migrate:status
```

### Accessing Container Shell

```bash
make shell
# or
docker compose exec laravel-app bash
```

### Viewing Logs

```bash
make logs
# or
docker compose logs -f laravel-app
```

### Database Operations

```bash
# Fresh migration
make migrate-fresh

# Run seeders
make seed

# Access PostgreSQL
docker compose exec postgis psql -U postgres -d laravel_gis
```

### Running Tests

```bash
make test
# or
docker compose exec laravel-app php artisan test
```

## Volume Management

Docker volumes persist data across container restarts:

- `postgis-data`: PostgreSQL/PostGIS database files
- `geoserver-data`: GeoServer configuration and data
- `redis-data`: Redis persistence

To remove all data and start fresh:

```bash
make clean
```

## Network Architecture

All services communicate through the `webgis-network` Docker bridge network:

- Laravel App ↔ PostGIS (database connection)
- Laravel App ↔ Redis (cache/queue)
- Laravel App ↔ GeoServer (API calls)
- Nginx ↔ Laravel App (FastCGI)

## GeoServer Configuration

GeoServer is pre-configured with:
- CORS enabled for cross-origin requests
- 2GB initial memory, 4GB maximum
- Admin interface at http://localhost:8080/geoserver

To connect GeoServer to PostGIS:
1. Access GeoServer admin panel
2. Create a new workspace: `webgis`
3. Add PostGIS store with connection:
   - Host: `postgis`
   - Port: `5432`
   - Database: `laravel_gis`
   - User: `postgres`
   - Password: the `DB_PASSWORD` in your local `.env` (`CHANGE_ME` until you set one). The stock local demo fallback is `secret` — **localhost only. Change it before any network exposure. Never use it on a public host.**

## Troubleshooting

### Containers won't start
```bash
# Check container status
docker compose ps

# View logs
make logs

# Rebuild containers
make down
make build
make up
```

### Permission issues
```bash
# Fix storage permissions
docker compose exec laravel-app chmod -R 775 storage bootstrap/cache
```

### Database connection errors
```bash
# Wait for PostGIS to be ready
docker compose exec postgis pg_isready -U postgres

# Check database exists
docker compose exec postgis psql -U postgres -l
```

## Testing

### Running Tests

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run with coverage (requires Xdebug)
php artisan test --coverage
```

### Test Database Setup

For full spatial test coverage, configure PostgreSQL:

```bash
# Create test database
docker compose exec postgis psql -U postgres -c "CREATE DATABASE laravel_gis_test;"
docker compose exec postgis psql -U postgres -d laravel_gis_test -c "CREATE EXTENSION postgis;"

# Update phpunit.xml to use PostgreSQL instead of SQLite
```

See [TESTING_GUIDE.md](TESTING_GUIDE.md) for complete testing documentation.

## Public repo security

This repository is public. Do not commit secrets, and never commit `.env` (it is gitignored). Sample passwords in this README are **local demo only**.

- Keep real credentials out of git, issues, and pull requests.
- **Change demo passwords before any network exposure. Never use them on a public host.**
- If a demo password (`password`, `geoserver`, or `secret`) was ever used outside localhost, rotate it.
- Production needs unique strong secrets. See [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md).

## Production Deployment

### Quick Production Setup

```bash
# 1. Configure environment
cp .env.production.example .env
nano .env  # Set unique strong secrets and your domain. Never reuse local demo passwords.

# 2. Generate SSL certificates (see docker/ssl/README.md)

# 3. Start production containers
docker compose -f docker-compose.production.yml up -d

# 4. Run migrations and optimize
docker compose -f docker-compose.production.yml exec laravel-app php artisan migrate --force
docker compose -f docker-compose.production.yml exec laravel-app php artisan config:cache
docker compose -f docker-compose.production.yml exec laravel-app php artisan route:cache
```

### Health Monitoring

```bash
# Check application health
curl https://your-domain.com/health

# Detailed status
curl https://your-domain.com/health/detailed

# Performance metrics
curl https://your-domain.com/health/metrics
```

See [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) for complete deployment documentation.

## Documentation

### Comprehensive Guides

- **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)** - Complete REST API reference
- **[USER_GUIDE.md](USER_GUIDE.md)** - End-user documentation and tutorials
- **[DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md)** - Production deployment instructions
- **[TESTING_GUIDE.md](TESTING_GUIDE.md)** - Testing framework and practices
- **[MONITORING_AND_LOGGING.md](MONITORING_AND_LOGGING.md)** - Monitoring and logging setup

### Feature-Specific Documentation

- **[AUTH_GUIDE.md](AUTH_GUIDE.md)** - Authentication and authorization
- **[SPATIAL_FEATURES.md](SPATIAL_FEATURES.md)** - Spatial database capabilities
- **[GEOSERVER_INTEGRATION.md](GEOSERVER_INTEGRATION.md)** - GeoServer integration
- **[DATA_IMPORT.md](DATA_IMPORT.md)** - Data import system
- **[GIS_TOOLS_DOCUMENTATION.md](GIS_TOOLS_DOCUMENTATION.md)** - GIS analysis tools
- **[MAP_BUILDER_README.md](MAP_BUILDER_README.md)** - Map builder getting started (React / Inertia)
- **[MAP_BUILDER_DOCUMENTATION.md](MAP_BUILDER_DOCUMENTATION.md)** - Map builder interface

### Quick References

- **[GIS_TOOLS_QUICK_REFERENCE.md](GIS_TOOLS_QUICK_REFERENCE.md)** - GIS tools quick reference
- **[MAP_BUILDER_QUICK_REFERENCE.md](MAP_BUILDER_QUICK_REFERENCE.md)** - Map builder quick reference

Living guides stay in the repository root. They were not moved under `docs/`.

### Historical snapshots

These files describe an earlier Vue 3 / Laravel 12 map builder. They are not the current stack. Start with [MAP_BUILDER_README.md](MAP_BUILDER_README.md) instead.

- [MAP_BUILDER_ARCHITECTURE.md](MAP_BUILDER_ARCHITECTURE.md)
- [MAP_BUILDER_CHECKLIST.md](MAP_BUILDER_CHECKLIST.md)
- [MAP_BUILDER_EXAMPLES.md](MAP_BUILDER_EXAMPLES.md)
- [MAP_BUILDER_FIXES.md](MAP_BUILDER_FIXES.md)
- [MAP_BUILDER_FINAL_SUMMARY.md](MAP_BUILDER_FINAL_SUMMARY.md)
- [MAP_BUILDER_IMPLEMENTATION_SUMMARY.md](MAP_BUILDER_IMPLEMENTATION_SUMMARY.md)
- [GIS_TOOLS_FEATURES.md](GIS_TOOLS_FEATURES.md)
- [GIS_TOOLS_IMPLEMENTATION_SUMMARY.md](GIS_TOOLS_IMPLEMENTATION_SUMMARY.md)
- [IMPLEMENTATION_COMPLETE.md](IMPLEMENTATION_COMPLETE.md)
- [IMPLEMENTATION_COMPLETE_SUMMARY.md](IMPLEMENTATION_COMPLETE_SUMMARY.md)
- [ENHANCEMENTS_SUMMARY.md](ENHANCEMENTS_SUMMARY.md)
- [WEBGIS_FIXES_SUMMARY.md](WEBGIS_FIXES_SUMMARY.md)

## Development

This project follows Docker best practices from [webgis.dev](https://webgis.dev) for optimal service communication and volume management.

## Contributing

Issues and pull requests are welcome on this repository.

## License

This project is licensed under the [MIT license](https://opensource.org/licenses/MIT).
