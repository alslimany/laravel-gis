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
        Schema::create('layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('table_name'); // PostGIS table name
            $table->string('geometry_type')->nullable(); // Point, LineString, Polygon, etc.
            $table->integer('feature_count')->default(0);
            $table->json('style_config')->nullable(); // Store style configuration (colors, symbols, etc.)
            $table->string('geoserver_layer_name')->nullable(); // GeoServer layer name
            $table->string('geoserver_workspace')->nullable(); // GeoServer workspace
            $table->boolean('published')->default(false); // Whether published to GeoServer
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();

            $table->index('project_id');
            $table->index('user_id');
            $table->index('organization_id');
            $table->index('published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('layers');
    }
};
