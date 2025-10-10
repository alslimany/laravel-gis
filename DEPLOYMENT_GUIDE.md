# Deployment Guide

Complete guide for deploying Laravel WebGIS to production.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Server Requirements](#server-requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [SSL/HTTPS Setup](#sslhttps-setup)
6. [Database Setup](#database-setup)
7. [GeoServer Configuration](#geoserver-configuration)
8. [Queue Workers](#queue-workers)
9. [Monitoring](#monitoring)
10. [Backup and Restore](#backup-and-restore)
11. [Maintenance](#maintenance)
12. [Troubleshooting](#troubleshooting)

## Prerequisites

### Software Requirements

- Docker 20.10+
- Docker Compose 1.29+
- Git
- Domain name with DNS configured (for production)
- SSL certificate (Let's Encrypt recommended)

### Hardware Requirements

**Minimum:**
- 2 CPU cores
- 4GB RAM
- 50GB storage

**Recommended:**
- 4+ CPU cores
- 8GB+ RAM
- 100GB+ SSD storage

## Server Requirements

### Operating System

Tested on:
- Ubuntu 20.04/22.04 LTS
- Debian 11+
- CentOS 8+
- Amazon Linux 2

### Firewall Configuration

Open the following ports:
- `80` - HTTP (redirects to HTTPS)
- `443` - HTTPS
- `22` - SSH (restrict to known IPs)

### DNS Configuration

Point your domain to server IP:
```
A     your-domain.com        → 192.0.2.1
CNAME www.your-domain.com    → your-domain.com
```

## Installation

### 1. Clone Repository

```bash
# Create application directory
sudo mkdir -p /var/www
cd /var/www

# Clone repository
git clone https://github.com/yourusername/laravel-gis.git
cd laravel-gis

# Set ownership
sudo chown -R $USER:$USER /var/www/laravel-gis
```

### 2. Environment Configuration

```bash
# Copy production environment file
cp .env.production.example .env

# Edit environment variables
nano .env
```

Update the following:
- `APP_URL`: Your domain (https://your-domain.com)
- `DB_PASSWORD`: Strong database password
- `REDIS_PASSWORD`: Strong Redis password
- `GEOSERVER_ADMIN_PASSWORD`: Strong GeoServer password
- Mail settings
- Other API keys as needed

### 3. Generate Application Key

```bash
docker compose -f docker-compose.production.yml run --rm laravel-app php artisan key:generate
```

### 4. Build and Start Services

```bash
# Build Docker images
docker compose -f docker-compose.production.yml build

# Start services
docker compose -f docker-compose.production.yml up -d

# Check status
docker compose -f docker-compose.production.yml ps
```

### 5. Database Migration

```bash
# Wait for database to be ready
sleep 10

# Run migrations
docker compose -f docker-compose.production.yml exec laravel-app php artisan migrate --force

# Seed initial data (optional)
docker compose -f docker-compose.production.yml exec laravel-app php artisan db:seed --force
```

### 6. Build Frontend Assets

```bash
# Install Node dependencies
docker compose -f docker-compose.production.yml exec laravel-app npm ci

# Build production assets
docker compose -f docker-compose.production.yml exec laravel-app npm run build
```

### 7. Optimize Application

```bash
docker compose -f docker-compose.production.yml exec laravel-app php artisan config:cache
docker compose -f docker-compose.production.yml exec laravel-app php artisan route:cache
docker compose -f docker-compose.production.yml exec laravel-app php artisan view:cache
```

## Configuration

### Application Settings

Key configuration files:
- `.env` - Environment variables
- `config/app.php` - Application settings
- `config/database.php` - Database configuration
- `config/geoserver.php` - GeoServer settings
- `config/logging.php` - Logging configuration

### Performance Tuning

#### PHP Configuration

Create `docker/php/production.ini`:

```ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
```

Mount in `docker-compose.production.yml`:
```yaml
volumes:
  - ./docker/php/production.ini:/usr/local/etc/php/conf.d/production.ini
```

#### Nginx Configuration

Edit `docker/nginx/production.conf` for:
- Buffer sizes
- Timeout values
- Gzip compression
- Static file caching

#### Database Optimization

PostgreSQL tuning in `docker/postgres/postgresql.conf`:

```conf
shared_buffers = 2GB
effective_cache_size = 6GB
maintenance_work_mem = 512MB
work_mem = 16MB
max_connections = 100
```

## SSL/HTTPS Setup

### Option 1: Let's Encrypt (Recommended)

```bash
# Stop nginx temporarily
docker compose -f docker-compose.production.yml stop nginx

# Generate certificate
sudo certbot certonly --standalone -d your-domain.com -d www.your-domain.com

# Copy certificates
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem docker/ssl/certificate.crt
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem docker/ssl/private.key

# Set permissions
sudo chmod 644 docker/ssl/certificate.crt
sudo chmod 600 docker/ssl/private.key

# Start nginx
docker compose -f docker-compose.production.yml start nginx
```

### Option 2: Custom Certificate

Place your certificate files:
- `docker/ssl/certificate.crt` - SSL certificate
- `docker/ssl/private.key` - Private key

### Auto-Renewal Setup

Add cron job for certificate renewal:

```bash
sudo crontab -e
```

Add:
```cron
0 0 * * * /usr/bin/certbot renew --quiet --post-hook "cd /var/www/laravel-gis && docker compose -f docker-compose.production.yml restart nginx"
```

## Database Setup

### Initial Setup

PostGIS is automatically initialized with the container.

### Connection Parameters

```env
DB_HOST=postgis
DB_PORT=5432
DB_DATABASE=laravel_gis_prod
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

### Create Additional Users

```bash
docker compose -f docker-compose.production.yml exec postgis psql -U postgres

CREATE USER app_user WITH PASSWORD 'secure_password';
GRANT ALL PRIVILEGES ON DATABASE laravel_gis_prod TO app_user;
GRANT ALL ON SCHEMA public TO app_user;
\q
```

## GeoServer Configuration

### Access GeoServer Admin

1. Navigate to `http://localhost:8081/geoserver`
2. Login with credentials from `.env`
3. Configure workspaces and data stores

### Create Workspace

```bash
docker compose -f docker-compose.production.yml exec laravel-app php artisan geoserver:test
```

### Production Settings

1. **Security**: Change default passwords
2. **CORS**: Configure allowed origins
3. **Caching**: Enable tile caching
4. **Logging**: Set appropriate log level

## Queue Workers

### Supervisor Configuration

Create `/etc/supervisor/conf.d/laravel-worker.conf`:

```conf
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=docker compose -f /var/www/laravel-gis/docker-compose.production.yml exec -T laravel-app php artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/laravel-gis/storage/logs/worker.log
stopwaitsecs=3600
```

Reload supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

### Alternative: Systemd Service

Create `/etc/systemd/system/laravel-queue.service`:

```ini
[Unit]
Description=Laravel Queue Worker
After=docker.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/laravel-gis
ExecStart=/usr/bin/docker compose -f docker-compose.production.yml exec -T queue-worker
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl enable laravel-queue
sudo systemctl start laravel-queue
```

## Monitoring

### Health Checks

Monitor application health:
```bash
curl https://your-domain.com/health
curl https://your-domain.com/health/detailed
curl https://your-domain.com/health/metrics
```

### Log Monitoring

View logs:
```bash
# Application logs
docker compose -f docker-compose.production.yml logs -f laravel-app

# Nginx logs
docker compose -f docker-compose.production.yml logs -f nginx

# Database logs
docker compose -f docker-compose.production.yml logs -f postgis

# All logs
docker compose -f docker-compose.production.yml logs -f
```

### System Monitoring

Recommended tools:
- **Prometheus** + **Grafana**: Metrics and visualization
- **Sentry**: Error tracking
- **New Relic**: APM monitoring
- **Datadog**: Infrastructure monitoring

### Log Management

Configure log aggregation:
- **ELK Stack**: Elasticsearch, Logstash, Kibana
- **Graylog**: Centralized logging
- **Papertrail**: Cloud logging

## Backup and Restore

### Automated Backups

Backups run automatically (daily by default).

Configure in `.env`:
```env
BACKUP_KEEP_DAYS=30
DB_BACKUP_ENABLED=true
```

### Manual Backup

```bash
# Database backup
docker compose -f docker-compose.production.yml exec postgis pg_dump -U postgres laravel_gis_prod | gzip > backup_$(date +%Y%m%d).sql.gz

# Application files
tar -czf app_backup_$(date +%Y%m%d).tar.gz \
  /var/www/laravel-gis \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='storage/logs/*'

# GeoServer data
tar -czf geoserver_backup_$(date +%Y%m%d).tar.gz /var/lib/docker/volumes/laravel-gis_geoserver-data
```

### Restore from Backup

```bash
# Stop application
docker compose -f docker-compose.production.yml down

# Restore database
gunzip -c backup_20251009.sql.gz | docker compose -f docker-compose.production.yml exec -T postgis psql -U postgres laravel_gis_prod

# Restore files
tar -xzf app_backup_20251009.tar.gz -C /

# Start application
docker compose -f docker-compose.production.yml up -d
```

### Off-site Backups

Configure automatic backup to cloud storage:

```bash
# Install AWS CLI or rclone
# Configure credentials

# Add to cron
0 2 * * * /usr/local/bin/backup-to-s3.sh
```

## Maintenance

### Update Application

```bash
# Pull latest code
cd /var/www/laravel-gis
git pull origin main

# Rebuild containers
docker compose -f docker-compose.production.yml build

# Run migrations
docker compose -f docker-compose.production.yml exec laravel-app php artisan migrate --force

# Rebuild assets
docker compose -f docker-compose.production.yml exec laravel-app npm run build

# Clear caches
docker compose -f docker-compose.production.yml exec laravel-app php artisan cache:clear
docker compose -f docker-compose.production.yml exec laravel-app php artisan config:cache
docker compose -f docker-compose.production.yml exec laravel-app php artisan route:cache
docker compose -f docker-compose.production.yml exec laravel-app php artisan view:cache

# Restart services
docker compose -f docker-compose.production.yml restart
```

### Clean Up Docker

```bash
# Remove unused images
docker image prune -a

# Remove unused volumes
docker volume prune

# Remove unused containers
docker container prune
```

### Database Maintenance

```bash
# Vacuum database
docker compose -f docker-compose.production.yml exec postgis psql -U postgres -d laravel_gis_prod -c "VACUUM ANALYZE;"

# Reindex
docker compose -f docker-compose.production.yml exec postgis psql -U postgres -d laravel_gis_prod -c "REINDEX DATABASE laravel_gis_prod;"
```

## Troubleshooting

### Service Won't Start

```bash
# Check logs
docker compose -f docker-compose.production.yml logs

# Check container status
docker compose -f docker-compose.production.yml ps

# Restart specific service
docker compose -f docker-compose.production.yml restart laravel-app
```

### Database Connection Issues

```bash
# Test database connection
docker compose -f docker-compose.production.yml exec laravel-app php artisan tinker
>>> DB::connection()->getPdo();

# Check PostgreSQL logs
docker compose -f docker-compose.production.yml logs postgis
```

### Permission Issues

```bash
# Fix storage permissions
docker compose -f docker-compose.production.yml exec laravel-app chmod -R 775 storage bootstrap/cache
docker compose -f docker-compose.production.yml exec laravel-app chown -R www-data:www-data storage bootstrap/cache
```

### High Memory Usage

```bash
# Check container resources
docker stats

# Optimize PHP
# Increase memory_limit in php.ini

# Optimize database
# Tune PostgreSQL configuration
```

### Slow Performance

1. Enable opcache
2. Configure Redis caching
3. Optimize database queries
4. Use CDN for static assets
5. Enable nginx gzip compression

### SSL Certificate Issues

```bash
# Test certificate
openssl s_client -connect your-domain.com:443 -servername your-domain.com

# Verify certificate files
openssl x509 -in docker/ssl/certificate.crt -text -noout

# Renew Let's Encrypt
sudo certbot renew --force-renewal
```

## Security Checklist

- [ ] Change all default passwords
- [ ] Configure firewall rules
- [ ] Enable SSL/HTTPS
- [ ] Set secure cookie settings
- [ ] Configure CORS properly
- [ ] Enable rate limiting
- [ ] Set up fail2ban
- [ ] Regular security updates
- [ ] Implement backup strategy
- [ ] Configure monitoring/alerts
- [ ] Review application logs
- [ ] Secure GeoServer admin interface
- [ ] Use environment variables for secrets
- [ ] Enable HSTS headers
- [ ] Configure CSP headers

## Performance Checklist

- [ ] Enable opcache
- [ ] Configure Redis caching
- [ ] Optimize database indexes
- [ ] Enable query caching
- [ ] Use CDN for assets
- [ ] Enable gzip compression
- [ ] Optimize images
- [ ] Minimize CSS/JS
- [ ] Enable browser caching
- [ ] Configure load balancing (if needed)

## Support

For deployment support:
- Review application logs
- Check [API Documentation](API_DOCUMENTATION.md)
- Consult [User Guide](USER_GUIDE.md)
- Open GitHub issue
- Contact system administrator
