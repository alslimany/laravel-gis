<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type'); // shapefile, geojson, kml, csv
            $table->bigInteger('file_size'); // in bytes
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->string('table_name')->nullable(); // Generated PostGIS table name
            $table->string('geometry_type')->nullable(); // Point, LineString, Polygon, etc.
            $table->integer('feature_count')->nullable();
            $table->integer('progress')->default(0); // 0-100
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable(); // Store additional file metadata
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('status');
            $table->index('user_id');
            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};
