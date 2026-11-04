<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Wave 2 Agent F: ICS-lite incident management (op periods, IAP, roles, tasks, event log, SitRep). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_operational_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('objectives')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'incident_id'], 'dm_op_periods_org_incident_idx');
            $table->index(['incident_id', 'starts_at'], 'dm_op_periods_incident_starts_idx');
        });

        Schema::create('dm_incident_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->foreignId('operational_period_id')->nullable()->constrained('dm_operational_periods')->nullOnDelete();
            $table->text('objectives')->nullable();
            $table->text('strategies')->nullable();
            $table->text('safety_message')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->index(['organization_id', 'incident_id'], 'dm_incident_plans_org_incident_idx');
            $table->index(['incident_id', 'status'], 'dm_incident_plans_incident_status_idx');
        });

        Schema::create('dm_incident_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->string('role', 64);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['incident_id', 'role'], 'dm_incident_roles_incident_role_uq');
            $table->index(['organization_id', 'incident_id'], 'dm_incident_roles_org_incident_idx');
        });

        Schema::create('dm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('assigned_avl_unit_id')->nullable()->constrained('dm_avl_units')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->json('location')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'incident_id'], 'dm_tasks_org_incident_idx');
            $table->index(['incident_id', 'status'], 'dm_tasks_incident_status_idx');
        });
        $this->addPointColumn('dm_tasks');

        Schema::create('dm_event_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->text('body')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['incident_id', 'created_at'], 'dm_event_log_incident_created_idx');
            $table->index(['organization_id', 'created_at'], 'dm_event_log_org_created_idx');
        });

        Schema::create('dm_sitreps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->json('casualties')->nullable();
            $table->json('resources')->nullable();
            $table->json('lifelines_status')->nullable();
            $table->text('next_steps')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->json('exported_json')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'incident_id'], 'dm_sitreps_org_incident_idx');
            $table->index(['incident_id', 'prepared_at'], 'dm_sitreps_incident_prepared_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_sitreps');
        Schema::dropIfExists('dm_event_log');
        Schema::dropIfExists('dm_tasks');
        Schema::dropIfExists('dm_incident_roles');
        Schema::dropIfExists('dm_incident_plans');
        Schema::dropIfExists('dm_operational_periods');
    }

    protected function addPointColumn(string $literalTable): void
    {
        if ($literalTable !== 'dm_tasks') {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_tasks ADD COLUMN geom geometry(Point, 4326)');
            DB::statement('CREATE INDEX dm_tasks_geom_gist ON dm_tasks USING GIST (geom)');
        } else {
            Schema::table('dm_tasks', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }
};
