<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 1 Agent E: zone/scenario exposure metrics + risk index on dm_exposure_summaries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_exposure_summaries', function (Blueprint $table) {
            $table->dropUnique('dm_exposure_unique');
        });

        Schema::table('dm_exposure_summaries', function (Blueprint $table) {
            $table->string('scenario_name', 128)->default('')->after('hazard_key');
            $table->unsignedBigInteger('hazard_zone_layer_id')->nullable()->after('asset_id');
            $table->unsignedBigInteger('hazard_feature_id')->nullable()->after('hazard_zone_layer_id');
            $table->unsignedInteger('population_exposed')->nullable()->after('in_hazard');
            $table->decimal('road_length_m', 14, 2)->nullable()->after('population_exposed');
            $table->unsignedInteger('building_count')->nullable()->after('road_length_m');
            $table->unsignedInteger('medical_facility_count')->nullable()->after('building_count');
            $table->unsignedInteger('critical_site_count')->nullable()->after('medical_facility_count');
            $table->unsignedInteger('staging_area_count')->nullable()->after('critical_site_count');
            $table->unsignedInteger('assets_exposed')->nullable()->after('staging_area_count');
            $table->decimal('hazard_level', 8, 4)->nullable()->after('assets_exposed');
            $table->decimal('exposure_score', 8, 4)->nullable()->after('hazard_level');
            $table->decimal('vulnerability_score', 8, 4)->nullable()->after('exposure_score');
            $table->decimal('risk_index', 12, 4)->nullable()->after('vulnerability_score');
            $table->json('weights_snapshot')->nullable()->after('meta');

            $table->index(['organization_id', 'scenario_name'], 'dm_exposure_org_scenario_idx');
            $table->index(['organization_id', 'risk_index'], 'dm_exposure_org_risk_idx');
            $table->unique(
                ['organization_id', 'hazard_key', 'scenario_name', 'asset_type', 'asset_id'],
                'dm_exposure_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('dm_exposure_summaries', function (Blueprint $table) {
            $table->dropUnique('dm_exposure_unique');
            $table->dropIndex('dm_exposure_org_scenario_idx');
            $table->dropIndex('dm_exposure_org_risk_idx');
            $table->dropColumn([
                'scenario_name',
                'hazard_zone_layer_id',
                'hazard_feature_id',
                'population_exposed',
                'road_length_m',
                'building_count',
                'medical_facility_count',
                'critical_site_count',
                'staging_area_count',
                'assets_exposed',
                'hazard_level',
                'exposure_score',
                'vulnerability_score',
                'risk_index',
                'weights_snapshot',
            ]);
        });

        Schema::table('dm_exposure_summaries', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'hazard_key', 'asset_type', 'asset_id'],
                'dm_exposure_unique',
            );
        });
    }
};
