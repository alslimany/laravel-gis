<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Early Warning Phase 1 schema (#58 / ADR-0001 §3, D12).
 * - Global: ew_forecasts (weather stored once).
 * - Org-scoped: subscriptions, risk_assessments, alert_rules, alert_logs.
 * Geometry stays on ew_basins (#59). BelongsToOrganization scope lands with D14;
 * org tables carry organization_id FK cascade for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ew_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('basin_code');
            $table->string('source')->default('open-meteo');
            $table->timestamp('fetched_at');
            $table->timestamp('forecast_at');
            $table->decimal('precipitation_mm', 8, 2)->nullable();
            $table->unsignedTinyInteger('precipitation_probability')->nullable();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->json('props')->nullable();
            $table->timestamps();

            $table->unique(['basin_code', 'source', 'forecast_at'], 'ew_forecasts_basin_source_at_unique');
            $table->index(['basin_code', 'forecast_at']);
            $table->index('fetched_at');
        });

        Schema::create('ew_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('basin_code');
            $table->timestamps();

            $table->unique(['organization_id', 'basin_code']);
            $table->index('basin_code');
        });

        Schema::create('ew_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('basin_code');
            $table->timestamp('forecast_at');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->string('risk_level', 16); // green|yellow|orange|red
            $table->decimal('score', 8, 3)->nullable();
            $table->json('inputs')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'basin_code', 'forecast_at'], 'ew_risk_org_basin_at_unique');
            $table->index(['organization_id', 'valid_until']);
            $table->index('basin_code');
        });

        Schema::create('ew_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('basin_code')->nullable(); // null = all subscribed basins
            $table->string('name');
            $table->string('metric')->default('precipitation_mm');
            $table->string('operator', 8)->default('>=');
            $table->decimal('threshold', 12, 4);
            $table->string('risk_level_min', 16)->nullable();
            $table->json('channels')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('ew_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained('ew_alert_rules')->nullOnDelete();
            $table->unsignedBigInteger('risk_assessment_id')->nullable();
            $table->string('basin_code');
            $table->string('channel', 32);
            $table->string('status', 32);
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'sent_at']);
            $table->index('basin_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ew_alert_logs');
        Schema::dropIfExists('ew_alert_rules');
        Schema::dropIfExists('ew_risk_assessments');
        Schema::dropIfExists('ew_subscriptions');
        Schema::dropIfExists('ew_forecasts');
    }
};
