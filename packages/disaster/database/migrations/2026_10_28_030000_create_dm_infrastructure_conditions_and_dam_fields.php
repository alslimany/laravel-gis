<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 1: infrastructure condition / inspection records + dam fields on critical sites. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_infrastructure_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('dm_critical_sites')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('condition_grade', 32);
            $table->timestamp('last_inspection_at')->nullable();
            $table->timestamp('next_inspection_due')->nullable();
            $table->string('maintenance_status', 64)->nullable();
            $table->string('inspector', 255)->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'next_inspection_due'], 'dm_infra_cond_org_due_idx');
            $table->index(['site_id', 'last_inspection_at'], 'dm_infra_cond_site_inspected_idx');
            $table->index('condition_grade');
        });

        Schema::table('dm_critical_sites', function (Blueprint $table) {
            $table->decimal('capacity_mcm', 12, 3)->nullable()->after('capacity');
            $table->decimal('height_m', 10, 2)->nullable()->after('capacity_mcm');
            $table->unsignedSmallInteger('year_built')->nullable()->after('height_m');
            $table->decimal('spillway_capacity', 12, 3)->nullable()->after('year_built');
            $table->unsignedBigInteger('downstream_zone_layer_id')->nullable()->after('spillway_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('dm_critical_sites', function (Blueprint $table) {
            $table->dropColumn([
                'capacity_mcm',
                'height_m',
                'year_built',
                'spillway_capacity',
                'downstream_zone_layer_id',
            ]);
        });

        Schema::dropIfExists('dm_infrastructure_conditions');
    }
};
