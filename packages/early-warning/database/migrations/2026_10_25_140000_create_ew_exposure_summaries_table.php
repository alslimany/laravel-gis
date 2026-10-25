<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: per-basin OSM exposure summaries (global, ADR D12).
 * Counts/lengths only — not full building/road geometries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ew_exposure_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('basin_code')->unique();
            $table->unsignedInteger('building_count')->default(0);
            $table->unsignedInteger('road_segment_count')->default(0);
            $table->decimal('road_km', 12, 3)->default(0);
            $table->decimal('bbox_south', 10, 6)->nullable();
            $table->decimal('bbox_west', 10, 6)->nullable();
            $table->decimal('bbox_north', 10, 6)->nullable();
            $table->decimal('bbox_east', 10, 6)->nullable();
            $table->string('source')->default('overpass');
            $table->timestamp('fetched_at')->nullable();
            $table->json('props')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ew_exposure_summaries');
    }
};
