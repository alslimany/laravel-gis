.PHONY: help build up down restart logs shell composer artisan migrate migrate-fresh seed test clean install prepare-env

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
	@echo "                     Requires unique DB_PASSWORD and GEOSERVER_ADMIN_PASSWORD in .env"

# Build Docker images
build:
	docker compose build

# Reject placeholder and well-known demo passwords before containers start.
prepare-env:
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "Created .env from .env.example."; \
		echo "LOCAL SETUP ONLY: set real DB_PASSWORD and GEOSERVER_ADMIN_PASSWORD, then re-run."; \
	fi
	@status=0; \
	for key in DB_PASSWORD GEOSERVER_ADMIN_PASSWORD; do \
		val=$$(sed -n "s/^$$key=//p" .env | head -n1 | tr -d '\r' | sed -e 's/^"//' -e 's/"$$//' -e "s/^'//" -e "s/'$$//"); \
		case "$$val" in \
			""|CHANGE_ME|secret|geoserver|password) \
				echo "ERROR: $$key is unset or still a placeholder or demo password."; \
				echo "Local setup only. Put a unique value in .env before install or first boot."; \
				status=1 ;; \
		esac; \
	done; \
	if [ $$status -ne 0 ]; then \
		exit $$status; \
	fi

# Start containers
up: prepare-env
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
	@echo "LOCAL SETUP ONLY: demo users are refused unless APP_ENV=local."
	@echo "Change the demo account before any network exposure."
	docker compose exec laravel-app php artisan db:seed

# Run tests
test:
	docker compose exec laravel-app php artisan test

# Clean up containers and volumes
clean:
	docker compose down -v
	docker system prune -f

# Complete installation
install: prepare-env build up
	@echo "Waiting for containers to start..."
	@sleep 5
	docker compose exec laravel-app composer install
	docker compose exec laravel-app php artisan key:generate
	@echo "Waiting for database to be ready..."
	@sleep 5
	docker compose exec laravel-app php artisan migrate --seed
	@sleep 2
	@echo "Installation npm packages..."
	docker compose exec laravel-app npm install
	docker compose exec laravel-app npm run build
	@echo "Installation complete!"
	@echo "LOCAL SETUP ONLY. DB_PASSWORD and GEOSERVER_ADMIN_PASSWORD must stay unique to this machine."
	@echo "PostGIS reads POSTGRES_PASSWORD only when the data volume is first created."
	@echo "Demo users from DatabaseSeeder run only when APP_ENV=local. Do not seed them on a public host."
	@echo "Application is running at http://localhost:8080"
	@echo "GeoServer is running at http://localhost:8080/geoserver"
