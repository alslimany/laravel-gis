<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for plugin lifecycle and per-org toggles (ADR-0001 D9).
     */
    public function up(): void
    {
        Schema::create('plugin_events', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_key', 64);
            $table->string('action', 32);
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_version', 32)->nullable();
            $table->string('to_version', 32)->nullable();
            $table->string('outcome', 16)->default('ok');
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['plugin_key', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_events');
    }
};
