<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 3: EDXL-HAVE / RM / SitRep / DE partner exchange tokens + audit log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_exchange_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at'], 'dm_exchange_tokens_org_revoked_idx');
        });

        Schema::create('dm_exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('direction', 8);
            $table->string('message_type', 64);
            $table->string('payload_hash', 64);
            $table->string('status', 32);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'dm_exchange_logs_org_created_idx');
            $table->index(['organization_id', 'message_type'], 'dm_exchange_logs_org_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_exchange_logs');
        Schema::dropIfExists('dm_exchange_tokens');
    }
};
