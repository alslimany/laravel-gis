<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Early Warning: global basin registry (ADR 0001 D12).
 * Global reference data: NO organization_id. Orgs subscribe to areas later
 * via an org-scoped subscription table. Geometry lives in the imported layer
 * (normal data-import flow); this table links to it by layer_id + feature_id.
 * Runs only via plugin:install (CLI/queue), not plain `artisan migrate`.
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
            $table->foreignId('layer_id')->nullable()->constrained('layers')->nullOnDelete();
            $table->string('feature_id')->nullable();          // gid/fid in the layer's import table
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
            $table->json('attributes')->nullable();            // any extra props from the pipeline
            $table->timestamps();

            $table->index('layer_id');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ew_basins');
    }
};
