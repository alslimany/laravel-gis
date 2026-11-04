<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 3: Full CAP 1.2 dissemination, message templates, SMS deliveries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_dissemination_logs', function (Blueprint $table) {
            $table->string('status', 32)->default('draft')->after('channel');
            $table->string('msg_type', 32)->nullable()->after('status');
            $table->string('identifier', 255)->nullable()->after('msg_type');
            $table->string('references_identifier', 255)->nullable()->after('identifier');
            $table->string('outcome', 64)->nullable()->after('references_identifier');
            $table->unsignedInteger('attempt_count')->default(0)->after('outcome');
            $table->timestamp('next_retry_at')->nullable()->after('attempt_count');
            $table->boolean('test_mode')->default(false)->after('next_retry_at');

            $table->index(['organization_id', 'status', 'next_retry_at'], 'dm_dissem_retry_idx');
            $table->index(['organization_id', 'identifier'], 'dm_dissem_identifier_idx');
            $table->index(['organization_id', 'channel', 'status'], 'dm_dissem_channel_status_idx');
        });

        Schema::create('dm_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('hazard_type', 64);
            $table->string('language', 8);
            $table->string('channel', 32);
            $table->string('headline', 255)->nullable();
            $table->text('body');
            $table->string('status', 32)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'hazard_type', 'language', 'channel'], 'dm_tpl_lookup_idx');
            $table->index(['organization_id', 'status'], 'dm_tpl_status_idx');
        });

        Schema::create('dm_sms_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('dissemination_log_id')->constrained('dm_dissemination_logs')->cascadeOnDelete();
            $table->string('to_ref', 255);
            $table->string('provider', 64);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'dm_sms_org_status_idx');
            $table->index(['dissemination_log_id', 'status'], 'dm_sms_log_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_sms_deliveries');
        Schema::dropIfExists('dm_message_templates');

        Schema::table('dm_dissemination_logs', function (Blueprint $table) {
            $table->dropIndex('dm_dissem_retry_idx');
            $table->dropIndex('dm_dissem_identifier_idx');
            $table->dropIndex('dm_dissem_channel_status_idx');
            $table->dropColumn([
                'status',
                'msg_type',
                'identifier',
                'references_identifier',
                'outcome',
                'attempt_count',
                'next_retry_at',
                'test_mode',
            ]);
        });
    }
};
