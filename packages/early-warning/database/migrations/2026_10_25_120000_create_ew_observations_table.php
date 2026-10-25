<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 satellite / reanalysis observations (global, ADR D12).
 * IMERG-class precip + SMAP-class soil wetness per basin outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ew_observations', function (Blueprint $table) {
            $table->id();
            $table->string('basin_code');
            $table->string('source')->default('nasa-power');
            $table->timestamp('observed_at');
            $table->timestamp('fetched_at');
            $table->decimal('precipitation_mm', 8, 2)->nullable();
            $table->decimal('soil_moisture', 8, 4)->nullable(); // 0–1 saturation
            $table->json('props')->nullable();
            $table->timestamps();

            $table->unique(['basin_code', 'source', 'observed_at'], 'ew_obs_basin_source_at_unique');
            $table->index(['basin_code', 'observed_at']);
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ew_observations');
    }
};
