<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disaster & EM Phase 0–2 schema. Org-scoped tables with dm_ prefix (ADR-0001 D15).
 * PostGIS geometry when pgsql; GeoJSON/WKT text on SQLite tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_medical_facilities', function (Blueprint $table) {
            $table->id();
            $table->string('facility_id');
            $table->string('name');
            $table->unsignedInteger('bed_capacity')->default(0);
            $table->unsignedInteger('available_beds')->default(0);
            $table->unsignedTinyInteger('trauma_level')->nullable();
            $table->string('generator_status', 32)->default('ONLINE');
            $table->string('resource_status', 32)->default('01_Available');
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'facility_id']);
        });
        $this->addPointColumn('dm_medical_facilities');

        Schema::create('dm_staging_areas', function (Blueprint $table) {
            $table->id();
            $table->string('staging_id');
            $table->string('name');
            $table->unsignedInteger('max_vehicle_cap')->default(0);
            $table->boolean('fuel_availability')->default(false);
            $table->string('manning_agency')->nullable();
            $table->string('resource_status', 32)->nullable();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'staging_id']);
        });
        $this->addPolygonColumn('dm_staging_areas');

        Schema::create('dm_critical_sites', function (Blueprint $table) {
            $table->id();
            $table->string('site_id');
            $table->string('type', 64);
            $table->string('name');
            $table->unsignedInteger('capacity')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'site_id']);
        });
        $this->addPointColumn('dm_critical_sites');

        Schema::create('dm_evac_corridors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('shelter_site_id')->nullable();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->json('props')->nullable();
            $table->timestamps();

            $table->foreign('shelter_site_id')->references('id')->on('dm_critical_sites')->nullOnDelete();
        });
        $this->addLineColumn('dm_evac_corridors');

        Schema::create('dm_exposure_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('hazard_key');
            $table->string('asset_type');
            $table->unsignedBigInteger('asset_id');
            $table->decimal('distance_m', 12, 2)->nullable();
            $table->boolean('in_hazard')->default(false);
            $table->timestamp('computed_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'hazard_key']);
            $table->unique(['organization_id', 'hazard_key', 'asset_type', 'asset_id'], 'dm_exposure_unique');
        });

        Schema::create('dm_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('external_id');
            $table->string('source')->default('console');
            $table->string('incident_type')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('status', 32)->default('open');
            $table->timestamp('opened_at');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
        });
        $this->addPointColumn('dm_incidents');

        Schema::create('dm_closures', function (Blueprint $table) {
            $table->id();
            $table->string('external_id');
            $table->string('closure_type')->nullable();
            $table->string('severity', 32)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->string('source')->default('ingest');
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
        });
        $this->addLineColumn('dm_closures');

        Schema::create('dm_avl_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_id');
            $table->string('resource_class', 32);
            $table->string('resource_status', 32)->default('01_Available');
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamp('last_ping_at')->nullable();
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('heading_deg', 6, 2)->nullable();
            $table->json('extras')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'unit_id']);
        });
        $this->addPointColumn('dm_avl_units');

        Schema::create('dm_avl_pings', function (Blueprint $table) {
            $table->id();
            $table->string('unit_id');
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->json('extras')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'unit_id', 'recorded_at']);
            $table->index('recorded_at');
        });
        $this->addPointColumn('dm_avl_pings');

        Schema::create('dm_public_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dm_dissemination_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('channel', 64);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_dissemination_logs');
        Schema::dropIfExists('dm_public_tokens');
        Schema::dropIfExists('dm_avl_pings');
        Schema::dropIfExists('dm_avl_units');
        Schema::dropIfExists('dm_closures');
        Schema::dropIfExists('dm_incidents');
        Schema::dropIfExists('dm_exposure_summaries');
        Schema::dropIfExists('dm_evac_corridors');
        Schema::dropIfExists('dm_critical_sites');
        Schema::dropIfExists('dm_staging_areas');
        Schema::dropIfExists('dm_medical_facilities');
    }

    protected function addPointColumn(string $literalTable): void
    {
        if ($literalTable === 'dm_medical_facilities') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_medical_facilities ADD COLUMN geom geometry(Point, 4326)');
                DB::statement('CREATE INDEX dm_medical_facilities_geom_gist ON dm_medical_facilities USING GIST (geom)');
            } else {
                Schema::table('dm_medical_facilities', fn (Blueprint $t) => $t->text('geom')->nullable());
            }

            return;
        }
        if ($literalTable === 'dm_critical_sites') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_critical_sites ADD COLUMN geom geometry(Point, 4326)');
                DB::statement('CREATE INDEX dm_critical_sites_geom_gist ON dm_critical_sites USING GIST (geom)');
            } else {
                Schema::table('dm_critical_sites', fn (Blueprint $t) => $t->text('geom')->nullable());
            }

            return;
        }
        if ($literalTable === 'dm_incidents') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_incidents ADD COLUMN geom geometry(Point, 4326)');
                DB::statement('CREATE INDEX dm_incidents_geom_gist ON dm_incidents USING GIST (geom)');
            } else {
                Schema::table('dm_incidents', fn (Blueprint $t) => $t->text('geom')->nullable());
            }

            return;
        }
        if ($literalTable === 'dm_avl_units') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_avl_units ADD COLUMN geom geometry(Point, 4326)');
                DB::statement('CREATE INDEX dm_avl_units_geom_gist ON dm_avl_units USING GIST (geom)');
            } else {
                Schema::table('dm_avl_units', fn (Blueprint $t) => $t->text('geom')->nullable());
            }

            return;
        }
        if ($literalTable === 'dm_avl_pings') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_avl_pings ADD COLUMN geom geometry(Point, 4326)');
                DB::statement('CREATE INDEX dm_avl_pings_geom_gist ON dm_avl_pings USING GIST (geom)');
            } else {
                Schema::table('dm_avl_pings', fn (Blueprint $t) => $t->text('geom')->nullable());
            }
        }
    }

    protected function addPolygonColumn(string $literalTable): void
    {
        if ($literalTable !== 'dm_staging_areas') {
            return;
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_staging_areas ADD COLUMN geom geometry(Polygon, 4326)');
            DB::statement('CREATE INDEX dm_staging_areas_geom_gist ON dm_staging_areas USING GIST (geom)');
        } else {
            Schema::table('dm_staging_areas', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }

    protected function addLineColumn(string $literalTable): void
    {
        if ($literalTable === 'dm_evac_corridors') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_evac_corridors ADD COLUMN geom geometry(LineString, 4326)');
                DB::statement('CREATE INDEX dm_evac_corridors_geom_gist ON dm_evac_corridors USING GIST (geom)');
            } else {
                Schema::table('dm_evac_corridors', fn (Blueprint $t) => $t->text('geom')->nullable());
            }

            return;
        }
        if ($literalTable === 'dm_closures') {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE dm_closures ADD COLUMN geom geometry(LineString, 4326)');
                DB::statement('CREATE INDEX dm_closures_geom_gist ON dm_closures USING GIST (geom)');
            } else {
                Schema::table('dm_closures', fn (Blueprint $t) => $t->text('geom')->nullable());
            }
        }
    }
};
