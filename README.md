# Laravel WebGIS Project

A Laravel-based WebGIS application with Docker infrastructure including PostGIS, GeoServer, Redis, and Nginx.

## Features

- **Laravel 12** - Modern PHP framework
- **PostGIS 13-3.1** - Spatial database extension for PostgreSQL
- **GeoServer 2.21.x** - Open source server for sharing geospatial data with CORS enabled
- **Redis** - In-memory data structure store for caching and queues
- **Nginx** - High-performance web server
- **Docker Compose** - Multi-container orchestration

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

### 3. Access the Application

- **Laravel Application**: http://localhost
- **GeoServer Admin**: http://localhost:8080/geoserver
  - Username: `admin`
  - Password: `geoserver`
- **PostgreSQL/PostGIS**: localhost:5432
  - Database: `laravel_gis`
  - Username: `postgres`
  - Password: `secret`

## Manual Installation

If you prefer to set up manually:

### 1. Build Docker images

```bash
make build
# or
docker-compose build
```

### 2. Start containers

```bash
make up
# or
docker-compose up -d
```

### 3. Install dependencies

```bash
make composer
# or
docker-compose exec laravel-app composer install
```

### 4. Configure environment

```bash
docker-compose exec laravel-app cp .env.example .env
docker-compose exec laravel-app php artisan key:generate
```

### 5. Run migrations

```bash
make migrate
# or
docker-compose exec laravel-app php artisan migrate
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
- **Image**: Custom (PHP 8.2-FPM)
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

Key environment variables in `.env`:

```env
# Database
DB_CONNECTION=pgsql
DB_HOST=postgis
DB_PORT=5432
DB_DATABASE=laravel_gis
DB_USERNAME=postgres
DB_PASSWORD=secret

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# GeoServer
GEOSERVER_URL=http://geoserver:8080/geoserver
GEOSERVER_ADMIN_USER=admin
GEOSERVER_ADMIN_PASSWORD=geoserver
GEOSERVER_WORKSPACE=webgis
GEOSERVER_DATASTORE=postgis_store
```

## Common Tasks

### Running Artisan Commands

```bash
make artisan CMD="migrate:status"
# or
docker-compose exec laravel-app php artisan migrate:status
```

### Accessing Container Shell

```bash
make shell
# or
docker-compose exec laravel-app bash
```

### Viewing Logs

```bash
make logs
# or
docker-compose logs -f laravel-app
```

### Database Operations

```bash
# Fresh migration
make migrate-fresh

# Run seeders
make seed

# Access PostgreSQL
docker-compose exec postgis psql -U postgres -d laravel_gis
```

### Running Tests

```bash
make test
# or
docker-compose exec laravel-app php artisan test
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
   - Password: `secret`

## Troubleshooting

### Containers won't start
```bash
# Check container status
docker-compose ps

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
docker-compose exec laravel-app chmod -R 775 storage bootstrap/cache
```

### Database connection errors
```bash
# Wait for PostGIS to be ready
docker-compose exec postgis pg_isready -U postgres

# Check database exists
docker-compose exec postgis psql -U postgres -l
```

## Development

This project follows Docker best practices from [webgis.dev](https://webgis.dev) for optimal service communication and volume management.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
