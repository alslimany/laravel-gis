<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Wave 1 Agent D: evacuation order approval + execution tracking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_evacuation_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('activation_id')->nullable()->constrained('dm_activations')->nullOnDelete();
            $table->json('layer_feature_ids')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_official')->default(false);
            $table->text('text_en')->nullable();
            $table->text('text_ar')->nullable();
            $table->unsignedInteger('estimated_population')->nullable();
            $table->unsignedInteger('evacuated_count')->default(0);
            $table->unsignedInteger('unaccounted_count')->default(0);
            $table->json('corridor_ids')->nullable();
            $table->json('shelter_site_ids')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'activation_id']);
        });

        $this->addPolygonColumn();

        Schema::create('dm_evacuation_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('evacuation_order_id')->constrained('dm_evacuation_orders')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('action', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['evacuation_order_id', 'created_at'], 'dm_evac_order_events_order_created_idx');
            $table->index(['organization_id', 'created_at'], 'dm_evac_order_events_org_created_idx');
        });

        if (! Schema::hasColumn('dm_critical_sites', 'remaining_capacity')) {
            Schema::table('dm_critical_sites', function (Blueprint $table) {
                $table->unsignedInteger('remaining_capacity')->nullable()->after('capacity');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_evacuation_order_events');
        Schema::dropIfExists('dm_evacuation_orders');

        if (Schema::hasColumn('dm_critical_sites', 'remaining_capacity')) {
            Schema::table('dm_critical_sites', function (Blueprint $table) {
                $table->dropColumn('remaining_capacity');
            });
        }
    }

    protected function addPolygonColumn(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_evacuation_orders ADD COLUMN zone_geom geometry(Polygon, 4326)');
            DB::statement('CREATE INDEX dm_evacuation_orders_zone_geom_gist ON dm_evacuation_orders USING GIST (zone_geom)');
        } else {
            Schema::table('dm_evacuation_orders', fn (Blueprint $t) => $t->text('zone_geom')->nullable());
        }
    }
};
