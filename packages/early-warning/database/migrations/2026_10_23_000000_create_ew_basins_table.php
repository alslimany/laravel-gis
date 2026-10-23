<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Early Warning: global basin registry (#59, ADR 0001 D12). See README "Decisions".
 * - Global reference data: NO organization_id (orgs subscribe to areas later).
 * - Geometry is stored HERE: geom geometry(MultiPolygon,4326), GIST-indexed,
 *   CHECK ST_IsValid. An imported org layer (layer_id + feature_id) is an
 *   optional view only: nullable, no foreign key, no cascade.
 * - Until #54's plugin:install, run by path in its own batch, after core:
 *   php artisan migrate --path=packages/early-warning/database/migrations --realpath
 * - Non-PostgreSQL drivers (SQLite test DB) get a nullable text geom (GeoJSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ew_basins', function (Blueprint $table) {
            $table->id();
            $table->string('basin_code')->unique();          // stable ID, e.g. TRP-MAJENIN
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('region')->default('tripoli');
            $table->unsignedBigInteger('layer_id')->nullable();  // optional org-layer view, no FK
            $table->string('feature_id')->nullable();          // gid/fid in that layer's import table
            $table->decimal('area_km2', 12, 4)->nullable();
            $table->decimal('mean_slope_deg', 8, 3)->nullable();
            $table->decimal('max_slope_deg', 8, 3)->nullable();
            $table->unsignedBigInteger('max_flow_acc_cells')->nullable();
            $table->decimal('max_flow_acc_km2', 12, 4)->nullable();
            $table->decimal('outlet_lon', 10, 6)->nullable();
            $table->decimal('outlet_lat', 10, 6)->nullable();
            $table->string('dem_source')->nullable();
            $table->string('dem_version')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('props')->nullable();                 // extra pipeline properties
            $table->timestamps();

            $table->index('layer_id');
            $table->index('region');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ew_basins ADD COLUMN geom geometry(MultiPolygon, 4326)');
            DB::statement('ALTER TABLE ew_basins ADD CONSTRAINT ew_basins_geom_valid CHECK (geom IS NULL OR ST_IsValid(geom))');
            DB::statement('CREATE INDEX ew_basins_geom_gist ON ew_basins USING GIST (geom)');
        } else {
            Schema::table('ew_basins', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ew_basins');
    }
};
