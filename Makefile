.PHONY: help build up down restart logs shell composer artisan migrate migrate-fresh seed test clean install

# Default target
help:
	@echo "Laravel WebGIS Docker Commands"
	@echo "==============================="
	@echo "make build         - Build Docker images"
	@echo "make up            - Start all containers"
	@echo "make down          - Stop all containers"
	@echo "make restart       - Restart all containers"
	@echo "make logs          - View container logs"
	@echo "make shell         - Access Laravel app shell"
	@echo "make composer      - Run composer install"
	@echo "make artisan       - Run artisan commands (e.g., make artisan CMD='migrate')"
	@echo "make migrate       - Run database migrations"
	@echo "make migrate-fresh - Fresh database with migrations"
	@echo "make seed          - Seed the database"
	@echo "make test          - Run tests"
	@echo "make clean         - Remove all containers and volumes"
	@echo "make install       - Complete installation (build, up, composer, key, migrate)"

# Build Docker images
build:
	docker compose build

# Start containers
up:
	docker compose up -d

# Stop containers
down:
	docker compose down

# Restart containers
restart:
	docker compose restart

# View logs
logs:
	docker compose logs -f

# Access Laravel app shell
shell:
	docker compose exec laravel-app bash

# Run composer install
composer:
	docker compose exec laravel-app composer install

# Run artisan commands
artisan:
	docker compose exec laravel-app php artisan $(CMD)

# Run migrations
migrate:
	docker compose exec laravel-app php artisan migrate

# Fresh database with migrations
migrate-fresh:
	docker compose exec laravel-app php artisan migrate:fresh

# Seed database
seed:
	docker compose exec laravel-app php artisan db:seed

# Run tests
test:
	docker compose exec laravel-app php artisan test

# Clean up containers and volumes
clean:
	docker compose down -v
	docker system prune -f

# Complete installation
install: build up
	@echo "Waiting for containers to start..."
	@sleep 5
	docker compose exec laravel-app composer install
	docker compose exec laravel-app cp .env.example .env || true
	docker compose exec laravel-app php artisan key:generate
	@echo "Waiting for database to be ready..."
	@sleep 5
	docker compose exec laravel-app php artisan migrate --seed
	@sleep 2
	@echo "Installation npm packages..."
	docker compose exec laravel-app npm install
	docker compose exec laravel-app npm run build
	@echo "Installation complete!"
	@echo "Application is running at http://localhost:8080"
	@echo "GeoServer is running at http://localhost:8080/geoserver"
