<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 1: Warning acknowledgement, escalation rules, append-only audit log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_warning_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activation_id')->constrained('dm_activations')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 64)->nullable();
            $table->string('channel', 32);
            $table->timestamps();

            $table->index(['activation_id', 'organization_id']);
            $table->index(['organization_id', 'role']);
        });

        Schema::create('dm_warning_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('dm_warning_recipients')->cascadeOnDelete();
            $table->timestamp('received_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('response', 32)->nullable();
            $table->timestamps();

            $table->unique('recipient_id');
            $table->index('acknowledged_at');
        });

        Schema::create('dm_escalation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('timeout_minutes');
            $table->string('next_recipient_role', 64);
            $table->string('channel', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'sort_order']);
        });

        Schema::create('dm_warning_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activation_id')->constrained('dm_activations')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['activation_id', 'created_at']);
            $table->index(['organization_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_warning_audit_log');
        Schema::dropIfExists('dm_escalation_rules');
        Schema::dropIfExists('dm_warning_acks');
        Schema::dropIfExists('dm_warning_recipients');
    }
};
