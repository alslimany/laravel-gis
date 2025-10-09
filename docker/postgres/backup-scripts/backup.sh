#!/bin/bash

# Database Backup Script for PostgreSQL/PostGIS
# This script creates automated database backups

set -e

# Configuration
BACKUP_DIR="/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.sql.gz"
KEEP_DAYS=${BACKUP_KEEP_DAYS:-30}

# Database connection
DB_HOST="${DB_HOST:-postgis}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${POSTGRES_DB}"
DB_USER="${POSTGRES_USER}"

echo "[$(date)] Starting database backup..."

# Wait for database to be ready
until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER"; do
  echo "Waiting for database to be ready..."
  sleep 2
done

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Perform backup
PGPASSWORD="$POSTGRES_PASSWORD" pg_dump \
  -h "$DB_HOST" \
  -p "$DB_PORT" \
  -U "$DB_USER" \
  -d "$DB_NAME" \
  --verbose \
  --format=plain \
  --no-owner \
  --no-acl \
  | gzip > "$BACKUP_FILE"

# Check if backup was successful
if [ $? -eq 0 ]; then
  echo "[$(date)] Backup completed successfully: $BACKUP_FILE"
  
  # Calculate backup size
  BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
  echo "[$(date)] Backup size: $BACKUP_SIZE"
else
  echo "[$(date)] Backup failed!"
  exit 1
fi

# Clean up old backups
echo "[$(date)] Cleaning up backups older than ${KEEP_DAYS} days..."
find "$BACKUP_DIR" -name "backup_*.sql.gz" -type f -mtime +${KEEP_DAYS} -delete

# List remaining backups
echo "[$(date)] Current backups:"
ls -lh "$BACKUP_DIR"/backup_*.sql.gz 2>/dev/null || echo "No backups found"

echo "[$(date)] Backup process completed"

# Sleep for 24 hours before next backup (if running as daemon)
if [ "${RUN_DAEMON}" = "true" ]; then
  echo "[$(date)] Sleeping for 24 hours until next backup..."
  sleep 86400
  exec "$0"
fi
