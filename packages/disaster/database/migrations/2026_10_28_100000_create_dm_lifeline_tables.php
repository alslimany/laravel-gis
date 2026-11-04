<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 2: Community Lifelines components + status history for COP roll-ups. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_lifeline_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('lifeline', 64);
            $table->string('name');
            $table->string('linked_asset_type', 64)->nullable();
            $table->unsignedBigInteger('linked_asset_id')->nullable();
            $table->unsignedBigInteger('layer_id')->nullable();
            $table->unsignedBigInteger('layer_feature_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'lifeline'], 'dm_lifeline_components_org_lifeline_idx');
            $table->index(
                ['organization_id', 'linked_asset_type', 'linked_asset_id'],
                'dm_lifeline_components_org_asset_idx',
            );
        });

        Schema::create('dm_lifeline_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('dm_lifeline_components')->cascadeOnDelete();
            $table->string('status', 32)->default('unknown');
            $table->text('reason')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['component_id', 'reported_at'], 'dm_lifeline_status_component_reported_idx');
            $table->index(['organization_id', 'reported_at'], 'dm_lifeline_status_org_reported_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_lifeline_status');
        Schema::dropIfExists('dm_lifeline_components');
    }
};
