#!/bin/bash

# Database Restore Script for PostgreSQL/PostGIS

set -e

# Check if backup file is provided
if [ -z "$1" ]; then
  echo "Usage: $0 <backup-file>"
  echo "Available backups:"
  ls -lh /backups/backup_*.sql.gz 2>/dev/null || echo "No backups found"
  exit 1
fi

BACKUP_FILE="$1"

# Check if file exists
if [ ! -f "$BACKUP_FILE" ]; then
  echo "Error: Backup file not found: $BACKUP_FILE"
  exit 1
fi

# Database connection
DB_HOST="${DB_HOST:-postgis}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${POSTGRES_DB}"
DB_USER="${POSTGRES_USER}"

echo "[$(date)] Starting database restore from: $BACKUP_FILE"

# Wait for database to be ready
until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER"; do
  echo "Waiting for database to be ready..."
  sleep 2
done

# Confirm restore
echo "WARNING: This will replace all data in database: $DB_NAME"
read -p "Are you sure you want to continue? (yes/no) " -r
if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
  echo "Restore cancelled"
  exit 0
fi

# Drop and recreate database
echo "[$(date)] Dropping existing database..."
PGPASSWORD="$POSTGRES_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "DROP DATABASE IF EXISTS $DB_NAME;"
PGPASSWORD="$POSTGRES_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "CREATE DATABASE $DB_NAME;"

# Restore PostGIS extension
echo "[$(date)] Enabling PostGIS extension..."
PGPASSWORD="$POSTGRES_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "CREATE EXTENSION IF NOT EXISTS postgis;"

# Restore backup
echo "[$(date)] Restoring backup..."
gunzip -c "$BACKUP_FILE" | PGPASSWORD="$POSTGRES_PASSWORD" psql \
  -h "$DB_HOST" \
  -p "$DB_PORT" \
  -U "$DB_USER" \
  -d "$DB_NAME" \
  --verbose

if [ $? -eq 0 ]; then
  echo "[$(date)] Database restore completed successfully!"
else
  echo "[$(date)] Database restore failed!"
  exit 1
fi
