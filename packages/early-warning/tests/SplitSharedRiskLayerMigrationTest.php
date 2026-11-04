<?php

namespace Lumina\EarlyWarning\Tests;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SplitSharedRiskLayerMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_renames_every_legacy_row_to_its_org_table(): void
    {
        $ids = [];
        foreach ([1, 2] as $_) {
            $org = Organization::factory()->create();
            $user = User::factory()->create(['organization_id' => $org->id]);
            $ids[$org->id] = Layer::query()->create([
                'organization_id' => $org->id, 'user_id' => $user->id, 'name' => 'Risk',
                'table_name' => 'ew_basin_risk', 'geometry_type' => 'MultiPolygon',
            ])->id;
        }

        $migration = require dirname(__DIR__).'/database/migrations/2026_11_01_120000_split_shared_ew_basin_risk_layer.php';
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame(0, Layer::query()->where('table_name', 'ew_basin_risk')->count());
        foreach ($ids as $orgId => $layerId) {
            $this->assertSame('ew_basin_risk_'.$orgId, Layer::query()->findOrFail($layerId)->table_name);
        }
    }

    public function test_duplicate_legacy_row_is_logged_repointed_and_deleted(): void
    {
        Log::spy();
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $attrs = ['organization_id' => $org->id, 'user_id' => $user->id, 'name' => 'Risk', 'geometry_type' => 'MultiPolygon'];
        $kept = Layer::query()->create($attrs + ['table_name' => 'ew_basin_risk_'.$org->id]);
        $dup = Layer::query()->create($attrs + ['table_name' => 'ew_basin_risk']);

        $mapId = DB::table('maps')->insertGetId([
            'organization_id' => $org->id, 'user_id' => $user->id, 'name' => 'M',
            'layers' => json_encode([['id' => $dup->id, 'visible' => true], ['id' => 999999]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require dirname(__DIR__).'/database/migrations/2026_11_01_120000_split_shared_ew_basin_risk_layer.php';
        $migration->up();

        $this->assertNull(Layer::query()->find($dup->id));
        $this->assertNotNull(Layer::query()->find($kept->id));
        $layers = json_decode(DB::table('maps')->where('id', $mapId)->value('layers'), true);
        $this->assertSame([$kept->id, 999999], array_map(fn ($l) => (int) $l['id'], $layers));
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $ctx['duplicate_layer_id'] === $dup->id
            && $ctx['kept_layer_id'] === $kept->id)->once();
    }

    public function test_relationship_collisions_and_self_relations_are_dropped_others_repointed(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $attrs = ['organization_id' => $org->id, 'user_id' => $user->id, 'geometry_type' => 'MultiPolygon'];
        $kept = Layer::query()->create($attrs + ['name' => 'Risk', 'table_name' => 'ew_basin_risk_'.$org->id]);
        $dup = Layer::query()->create($attrs + ['name' => 'Risk old', 'table_name' => 'ew_basin_risk']);
        $other = Layer::query()->create($attrs + ['name' => 'Basins', 'table_name' => 'ew_basins_view']);
        $rel = fn (int $a, int $b, string $fk) => DB::table('layer_relationships')->insertGetId([
            'layer_id' => $a, 'related_layer_id' => $b, 'name' => "r{$a}-{$b}-{$fk}", 'foreign_key' => $fk,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keptOwn = $rel($kept->id, $other->id, 'basin_code');   // kept already links to other
        $collide = $rel($dup->id, $other->id, 'basin_code');    // same link from dup -> would collide
        $self1 = $rel($dup->id, $kept->id, 'basin_code');       // would become kept -> kept
        $self2 = $rel($kept->id, $dup->id, 'basin_code');       // would become kept -> kept
        $moved = $rel($other->id, $dup->id, 'risk_id');         // plain: repointed to kept

        $dashId = DB::table('dashboards')->insertGetId([
            'organization_id' => $org->id, 'user_id' => $user->id, 'name' => 'D',
            'widgets' => json_encode([['id' => 'w1', 'layer_id' => $dup->id, 'section' => ['widgets' => [['layer_id' => $dup->id]]]]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require dirname(__DIR__).'/database/migrations/2026_11_01_120000_split_shared_ew_basin_risk_layer.php';
        $migration->up();

        $this->assertNull(Layer::query()->find($dup->id));
        $ids = DB::table('layer_relationships')->pluck('id')->all();
        $this->assertContains($keptOwn, $ids);
        $this->assertNotContains($collide, $ids);
        $this->assertNotContains($self1, $ids);
        $this->assertNotContains($self2, $ids);
        $this->assertSame($kept->id, (int) DB::table('layer_relationships')->where('id', $moved)->value('related_layer_id'));
        $this->assertSame(0, DB::table('layer_relationships')->whereColumn('layer_id', 'related_layer_id')->count());

        $widgets = json_decode(DB::table('dashboards')->where('id', $dashId)->value('widgets'), true);
        $this->assertSame($kept->id, $widgets[0]['layer_id']);
        $this->assertSame($kept->id, $widgets[0]['section']['widgets'][0]['layer_id']);
    }
}
