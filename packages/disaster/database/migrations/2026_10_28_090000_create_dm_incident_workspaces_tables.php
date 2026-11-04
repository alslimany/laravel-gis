<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wave 2: Inter-agency incident workspace (controlled cross-org sharing). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_incident_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->foreignId('owner_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique('incident_id', 'dm_incident_workspaces_incident_uq');
            $table->index(['owner_organization_id', 'status'], 'dm_incident_workspaces_owner_status_idx');
        });

        Schema::create('dm_workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('dm_incident_workspaces')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('viewer');
            $table->json('scope')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'organization_id'], 'dm_workspace_members_ws_org_idx');
            $table->index(['organization_id', 'revoked_at', 'expires_at'], 'dm_workspace_members_org_active_idx');
            $table->index(['expires_at', 'revoked_at'], 'dm_workspace_members_expiry_idx');
        });

        Schema::create('dm_workspace_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('dm_incident_workspaces')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->foreignId('actor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at'], 'dm_workspace_access_log_ws_created_idx');
            $table->index(['incident_id', 'created_at'], 'dm_workspace_access_log_incident_created_idx');
            $table->index(['actor_organization_id', 'created_at'], 'dm_workspace_access_log_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_workspace_access_log');
        Schema::dropIfExists('dm_workspace_members');
        Schema::dropIfExists('dm_incident_workspaces');
    }
};
