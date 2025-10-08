<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // Only add spatial column for PostgreSQL
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE projects ADD COLUMN bounding_box GEOMETRY(POLYGON, 4326)');
            DB::statement('CREATE INDEX projects_bounding_box_idx ON projects USING GIST (bounding_box)');
        } else {
            // For SQLite testing, use a simple text column
            Schema::table('projects', function (Blueprint $table) {
                $table->text('bounding_box')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
