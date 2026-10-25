<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: citizen reports + hashed public feed tokens (ADR §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ew_public_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->json('abilities')->nullable(); // feed, report
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at']);
        });

        Schema::create('ew_citizen_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('basin_code')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->string('source', 32)->default('public');
            $table->string('reporter_contact')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['organization_id', 'status', 'reported_at']);
            $table->index('basin_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ew_citizen_reports');
        Schema::dropIfExists('ew_public_tokens');
    }
};
