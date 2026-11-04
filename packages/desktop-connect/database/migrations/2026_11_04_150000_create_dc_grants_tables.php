<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DG-1.5: desktop grants + append-only grant events.
 *
 * Grants are keyed by layers.id (FK). layers.public_id / alias (DG-1.1 / ADR-0002 §7)
 * are not in core yet — join/ability keys by public_id are a follow-up once that
 * core migration lands. Do not wait on public_id for this MVP slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dc_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('layers')->cascadeOnDelete();
            $table->string('scope', 16); // read | edit
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'layer_id'], 'dc_grants_org_layer_idx');
            $table->index(['organization_id', 'user_id'], 'dc_grants_org_user_idx');
            $table->index(['organization_id', 'group_id'], 'dc_grants_org_group_idx');
            $table->index(['expires_at'], 'dc_grants_expires_idx');

            // One active grant per subject+layer+scope (subject is user XOR group).
            $table->unique(
                ['organization_id', 'user_id', 'layer_id', 'scope'],
                'dc_grants_org_user_layer_scope_uq'
            );
            $table->unique(
                ['organization_id', 'group_id', 'layer_id', 'scope'],
                'dc_grants_org_group_layer_scope_uq'
            );
        });

        Schema::create('dc_grant_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('grant_id')->nullable();
            $table->string('action', 32); // granted | revoked
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'dc_grant_events_org_created_idx');
            $table->index(['grant_id', 'created_at'], 'dc_grant_events_grant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dc_grant_events');
        Schema::dropIfExists('dc_grants');
    }
};
