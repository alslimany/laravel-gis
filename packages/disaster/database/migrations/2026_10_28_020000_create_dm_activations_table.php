<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 1: WarningIssued → proposed activations (org-scoped, dm_ prefix). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('source', 64);
            $table->string('source_alert_id', 120);
            $table->string('hazard_type', 64);
            $table->string('severity', 32)->nullable();
            $table->string('urgency', 32)->nullable();
            $table->string('certainty', 32)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->json('affected')->nullable();
            $table->text('advisory_text')->nullable();
            $table->boolean('is_exercise')->default(false);
            $table->string('status', 32)->default('proposed');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'source', 'source_alert_id'],
                'dm_activations_org_source_alert_unique',
            );
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_activations');
    }
};
