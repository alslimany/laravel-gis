<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3: field damage assessment points (org-scoped, dm_ prefix). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_damage_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('damage_level', 32);
            $table->text('notes')->nullable();
            $table->timestamp('assessed_at');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('props')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id'], 'dm_damage_points_org_external_unique');
            $table->index(['organization_id', 'assessed_at']);
            $table->index('damage_level');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_damage_points ADD COLUMN geom geometry(Point, 4326)');
            DB::statement('CREATE INDEX dm_damage_points_geom_gist ON dm_damage_points USING GIST (geom)');
        } else {
            Schema::table('dm_damage_points', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_damage_points');
    }
};
