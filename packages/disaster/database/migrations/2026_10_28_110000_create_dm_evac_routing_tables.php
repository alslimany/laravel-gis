<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Wave 3: closure/capacity-aware evacuation routing tables + corridor metadata. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dm_evac_corridors')) {
            Schema::table('dm_evac_corridors', function (Blueprint $table) {
                if (! Schema::hasColumn('dm_evac_corridors', 'source')) {
                    $table->string('source', 32)->default('manual')->after('name');
                }
                if (! Schema::hasColumn('dm_evac_corridors', 'distance_m')) {
                    $table->decimal('distance_m', 12, 2)->nullable()->after('shelter_site_id');
                }
                if (! Schema::hasColumn('dm_evac_corridors', 'travel_time_s')) {
                    $table->unsignedInteger('travel_time_s')->nullable()->after('distance_m');
                }
                if (! Schema::hasColumn('dm_evac_corridors', 'evacuation_order_id')) {
                    $table->foreignId('evacuation_order_id')
                        ->nullable()
                        ->after('travel_time_s')
                        ->constrained('dm_evacuation_orders')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('dm_evac_corridors', 'computed_at')) {
                    $table->timestamp('computed_at')->nullable()->after('evacuation_order_id');
                }
            });
        }

        Schema::create('dm_road_network_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('from_node', 64);
            $table->string('to_node', 64);
            $table->decimal('length_m', 12, 2);
            $table->unsignedInteger('free_flow_s')->default(0);
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->json('props')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'from_node'], 'dm_road_edges_org_from_idx');
            $table->index(['organization_id', 'to_node'], 'dm_road_edges_org_to_idx');
            $table->index(['organization_id', 'is_blocked'], 'dm_road_edges_org_blocked_idx');
            $table->unique(['organization_id', 'external_id'], 'dm_road_edges_org_external_uid');
        });
        $this->addLineColumn('dm_road_network_edges');

        Schema::create('dm_evac_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('evacuation_order_id')
                ->nullable()
                ->constrained('dm_evacuation_orders')
                ->nullOnDelete();
            $table->string('zone_key');
            $table->foreignId('shelter_site_id')->constrained('dm_critical_sites')->cascadeOnDelete();
            $table->unsignedInteger('population');
            $table->foreignId('corridor_id')
                ->nullable()
                ->constrained('dm_evac_corridors')
                ->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'shelter_site_id'], 'dm_evac_assign_org_shelter_idx');
            $table->index(['organization_id', 'evacuation_order_id'], 'dm_evac_assign_org_order_idx');
            $table->unique(
                ['organization_id', 'evacuation_order_id', 'zone_key'],
                'dm_evac_assign_org_order_zone_uid',
            );
        });

        Schema::create('dm_routing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('evacuation_order_id')
                ->nullable()
                ->constrained('dm_evacuation_orders')
                ->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->json('metrics')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_routing_runs');
        Schema::dropIfExists('dm_evac_assignments');
        Schema::dropIfExists('dm_road_network_edges');

        if (Schema::hasTable('dm_evac_corridors')) {
            Schema::table('dm_evac_corridors', function (Blueprint $table) {
                foreach (['computed_at', 'evacuation_order_id', 'travel_time_s', 'distance_m', 'source'] as $column) {
                    if (Schema::hasColumn('dm_evac_corridors', $column)) {
                        if ($column === 'evacuation_order_id') {
                            $table->dropConstrainedForeignId('evacuation_order_id');
                        } else {
                            $table->dropColumn($column);
                        }
                    }
                }
            });
        }
    }

    protected function addLineColumn(string $literalTable): void
    {
        if ($literalTable !== 'dm_road_network_edges') {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_road_network_edges ADD COLUMN geom geometry(LineString, 4326)');
            DB::statement('CREATE INDEX dm_road_network_edges_geom_gist ON dm_road_network_edges USING GIST (geom)');
        } else {
            Schema::table('dm_road_network_edges', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }
};
