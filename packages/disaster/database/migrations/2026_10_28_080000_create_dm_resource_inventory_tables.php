<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Wave 2: Resource requests, inventory ledger, warehouses, volunteers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_resource_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 64)->nullable();
            $table->string('capability', 128)->nullable();
            $table->string('unit', 32)->default('ea');
            $table->timestamps();

            $table->index(['organization_id', 'category'], 'dm_resource_types_org_category_idx');
            $table->unique(['organization_id', 'name'], 'dm_resource_types_org_name_uq');
        });

        Schema::create('dm_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'dm_warehouses_org_status_idx');
        });
        $this->addPointColumn('dm_warehouses');

        Schema::create('dm_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('dm_warehouses')->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained('dm_resource_types')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('expiry')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'resource_type_id'], 'dm_inventory_items_wh_type_uq');
            $table->index(['organization_id', 'resource_type_id'], 'dm_inventory_items_org_type_idx');
        });

        Schema::create('dm_resource_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('dm_incidents')->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained('dm_resource_types')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('priority', 16)->default('normal');
            $table->timestamp('needed_by')->nullable();
            $table->string('status', 32)->default('requested');
            $table->json('edxl_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'dm_resource_requests_org_status_idx');
            $table->index(['incident_id', 'status'], 'dm_resource_requests_incident_status_idx');
        });

        Schema::create('dm_resource_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('request_id')->constrained('dm_resource_requests')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('dm_warehouses')->nullOnDelete();
            $table->foreignId('avl_unit_id')->nullable()->constrained('dm_avl_units')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('eta')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['request_id'], 'dm_resource_assignments_request_idx');
            $table->index(['organization_id', 'warehouse_id'], 'dm_resource_assignments_org_wh_idx');
        });

        Schema::create('dm_inventory_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('dm_warehouses')->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained('dm_resource_types')->restrictOnDelete();
            $table->integer('delta');
            $table->string('reason', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('dm_resource_requests')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['warehouse_id', 'created_at'], 'dm_inventory_ledger_wh_created_idx');
            $table->index(['organization_id', 'created_at'], 'dm_inventory_ledger_org_created_idx');
            $table->index(['request_id'], 'dm_inventory_ledger_request_idx');
        });

        Schema::create('dm_volunteers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->json('skills')->nullable();
            $table->string('availability', 64)->default('available');
            $table->string('organization_name')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'dm_volunteers_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_volunteers');
        Schema::dropIfExists('dm_inventory_ledger');
        Schema::dropIfExists('dm_resource_assignments');
        Schema::dropIfExists('dm_resource_requests');
        Schema::dropIfExists('dm_inventory_items');
        Schema::dropIfExists('dm_warehouses');
        Schema::dropIfExists('dm_resource_types');
    }

    protected function addPointColumn(string $literalTable): void
    {
        if ($literalTable !== 'dm_warehouses') {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_warehouses ADD COLUMN geom geometry(Point, 4326)');
            DB::statement('CREATE INDEX dm_warehouses_geom_gist ON dm_warehouses USING GIST (geom)');
        } else {
            Schema::table('dm_warehouses', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }
};
