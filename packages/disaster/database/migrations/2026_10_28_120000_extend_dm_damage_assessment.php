<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 3: structured damage assessment forms, photos, review, imagery. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_damage_points', function (Blueprint $table) {
            $table->string('structure_type')->nullable()->after('damage_level');
            $table->string('habitability')->nullable()->after('structure_type');
            $table->json('hazards_present')->nullable()->after('habitability');
            $table->json('urgent_needs')->nullable()->after('hazards_present');
            $table->unsignedInteger('people_affected')->nullable()->after('urgent_needs');
            $table->string('review_status', 32)->default('submitted')->after('people_affected');
            $table->foreignId('reviewed_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('reviewer_comments')->nullable()->after('reviewed_at');
            $table->foreignId('incident_id')->nullable()->after('reviewer_comments')->constrained('dm_incidents')->nullOnDelete();
            $table->foreignId('lifeline_component_id')->nullable()->after('incident_id')->constrained('dm_lifeline_components')->nullOnDelete();
            $table->string('form_schema_version')->nullable()->after('lifeline_component_id');

            $table->index(['organization_id', 'review_status'], 'dm_damage_points_org_review_idx');
            $table->index(['organization_id', 'incident_id'], 'dm_damage_points_org_incident_idx');
        });

        Schema::create('dm_damage_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('damage_point_id')->constrained('dm_damage_points')->cascadeOnDelete();
            $table->string('storage_path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['damage_point_id', 'sort_order'], 'dm_damage_photos_point_sort_idx');
            $table->index(['organization_id', 'damage_point_id'], 'dm_damage_photos_org_point_idx');
        });

        Schema::create('dm_damage_form_schemas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->json('schema');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'is_default'], 'dm_damage_form_schemas_org_default_idx');
        });

        Schema::create('dm_incident_imagery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->string('role', 16);
            $table->unsignedBigInteger('layer_id');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'role'], 'dm_incident_imagery_incident_role_uid');
            $table->index(['organization_id', 'incident_id'], 'dm_incident_imagery_org_incident_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_incident_imagery');
        Schema::dropIfExists('dm_damage_form_schemas');
        Schema::dropIfExists('dm_damage_photos');

        Schema::table('dm_damage_points', function (Blueprint $table) {
            $table->dropIndex('dm_damage_points_org_review_idx');
            $table->dropIndex('dm_damage_points_org_incident_idx');
            $table->dropConstrainedForeignId('lifeline_component_id');
            $table->dropConstrainedForeignId('incident_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'structure_type',
                'habitability',
                'hazards_present',
                'urgent_needs',
                'people_affected',
                'review_status',
                'reviewed_at',
                'reviewer_comments',
                'form_schema_version',
            ]);
        });
    }
};
