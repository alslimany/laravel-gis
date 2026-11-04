<?php

namespace Lumina\Disaster\Tests;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\InfrastructureCondition;
use Lumina\Disaster\Providers\DisasterServiceProvider;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class InfrastructureConditionTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_condition_crud_and_grade_enum_validation(): void
    {
        $site = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'DAM-1',
            'type' => CriticalSiteType::Dam->value,
            'name' => 'Wadi Dam',
            'capacity_mcm' => 12.5,
            'height_m' => 45.0,
            'year_built' => 1978,
            'downstream_zone_layer_id' => 99,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/infrastructure-conditions', [
                'site_id' => $site->id,
                'condition_grade' => 'excellent',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['condition_grade']);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/infrastructure-conditions', [
                'site_id' => $site->id,
                'condition_grade' => InfrastructureConditionGrade::Fair->value,
                'last_inspection_at' => now()->subMonth()->toDateString(),
                'next_inspection_due' => now()->addMonth()->toDateString(),
                'maintenance_status' => 'scheduled',
                'inspector' => 'Eng. A',
                'notes' => 'Spillway clear',
                'attachments' => ['https://example.test/report.pdf'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.condition_grade', 'fair')
            ->assertJsonPath('data.site_id', $site->id);

        $mine = InfrastructureCondition::query()->firstOrFail();

        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/infrastructure-conditions/'.$mine->id, [
                'condition_grade' => InfrastructureConditionGrade::Poor->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.condition_grade', 'poor');

        $otherOrg = Organization::factory()->create();
        $foreignSite = CriticalSite::query()->create([
            'organization_id' => $otherOrg->id,
            'site_id' => 'DAM-X',
            'type' => CriticalSiteType::Dam->value,
            'name' => 'Foreign',
        ]);
        $foreign = InfrastructureCondition::query()->create([
            'organization_id' => $otherOrg->id,
            'site_id' => $foreignSite->id,
            'condition_grade' => InfrastructureConditionGrade::Good->value,
            'next_inspection_due' => now()->subDay(),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->putJson('/x/disaster/infrastructure-conditions/'.$foreign->id, ['notes' => 'nope'])
            ->assertNotFound();

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/infrastructure-conditions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->disasterAdmin)
            ->deleteJson('/x/disaster/infrastructure-conditions/'.$mine->id)
            ->assertOk();

        $this->assertDatabaseMissing('dm_infrastructure_conditions', ['id' => $mine->id]);
    }

    public function test_overdue_inspections_list_on_api_and_assets_console(): void
    {
        $site = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'RES-1',
            'type' => CriticalSiteType::Reservoir->value,
            'name' => 'Reservoir A',
        ]);

        InfrastructureCondition::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => $site->id,
            'condition_grade' => InfrastructureConditionGrade::Critical->value,
            'next_inspection_due' => now()->subDays(3),
            'inspector' => 'Team B',
        ]);

        InfrastructureCondition::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => $site->id,
            'condition_grade' => InfrastructureConditionGrade::Good->value,
            'next_inspection_due' => now()->addWeek(),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->getJson('/x/disaster/infrastructure-conditions/overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.condition_grade', 'critical');

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Assets')
                ->has('overdue_inspections', 1)
                ->has('condition_grades')
                ->has('condition_store_url')
                ->where('critical_site_types', function ($types) {
                    $values = collect($types)->all();

                    return in_array('dam', $values, true) && in_array('hospital_field', $values, true);
                })
            );
    }

    public function test_new_critical_site_types_and_dam_fields_accepted(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/critical-sites', [
                'site_id' => 'PORT-1',
                'type' => CriticalSiteType::Port->value,
                'name' => 'Main Port',
                'capacity_mcm' => null,
                'downstream_zone_layer_id' => 7,
                'latitude' => 32.9,
                'longitude' => 13.2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'port')
            ->assertJsonPath('data.downstream_zone_layer_id', 7);

        $this->assertCount(13, CriticalSiteType::cases());
        $this->assertCount(4, InfrastructureConditionGrade::cases());
    }

    public function test_core_schema_untouched_by_disaster_plugin_tables(): void
    {
        $ignorePrefixes = ['dm_', 'ew_', 'hello_', 'migrations', 'sqlite_'];

        $snapshot = function () use ($ignorePrefixes): array {
            $out = [];
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                foreach ($ignorePrefixes as $prefix) {
                    if (str_starts_with($table, $prefix) || $table === $prefix) {
                        continue 2;
                    }
                }
                $out[$table] = [
                    'columns' => collect(Schema::getColumns($table))
                        ->map(fn ($c) => [$c['name'], $c['type'], $c['nullable']])->all(),
                    'indexes' => collect(Schema::getIndexes($table))
                        ->map(fn ($i) => [$i['name'], $i['columns'], $i['unique']])->sortBy(0)->values()->all(),
                ];
            }
            ksort($out);

            return $out;
        };

        // Plugin already installed in setUp; assert core tables still look like a fresh app schema
        // by comparing organizations/users/layers column sets remain present and unchanged shape.
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('dm_infrastructure_conditions'));
        $this->assertTrue(Schema::hasColumn('dm_critical_sites', 'downstream_zone_layer_id'));

        $core = $snapshot();
        $this->assertArrayHasKey('organizations', $core);
        $this->assertArrayHasKey('users', $core);
        $this->assertArrayNotHasKey('dm_infrastructure_conditions', $core);

        // Re-running migrate for plugin path must not alter core snapshot.
        $before = $core;
        $this->artisan('migrate', [
            '--path' => DisasterServiceProvider::migrationsPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
        $this->assertSame($before, $snapshot());
    }
}
