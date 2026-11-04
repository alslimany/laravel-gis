<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DG-1.1 — public_id / alias on layers; hide table_name from non-super-admins.
 */
class LayerPublicIdAndAliasTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'editor', 'description' => 'Editor']);

        $this->organization = Organization::factory()->create();

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user->roles()->attach(Role::where('name', 'editor')->first());

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->superAdmin->forceFill(['is_super_admin' => true])->save();
    }

    public function test_migration_adds_public_id_and_alias_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('layers', 'public_id'));
        $this->assertTrue(Schema::hasColumn('layers', 'alias'));
    }

    public function test_backfill_assigns_ulids_and_unique_aliases_under_refresh_database(): void
    {
        $path = 'database/migrations/2026_11_04_141000_add_public_id_and_alias_to_layers_table.php';

        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])
            ->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('layers', 'public_id'));

        $idA = DB::table('layers')->insertGetId([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'name' => 'Harbor Roads',
            'table_name' => 'import_org'.$this->organization->id.'_harbor_roads',
            'geometry_type' => 'LineString',
            'feature_count' => 0,
            'published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idB = DB::table('layers')->insertGetId([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'name' => 'Harbor Roads',
            'table_name' => 'import_org'.$this->organization->id.'_harbor_roads_b',
            'geometry_type' => 'LineString',
            'feature_count' => 0,
            'published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => $path, '--force' => true])
            ->assertSuccessful();

        $a = DB::table('layers')->where('id', $idA)->first();
        $b = DB::table('layers')->where('id', $idB)->first();

        $this->assertNotNull($a->public_id);
        $this->assertNotNull($b->public_id);
        $this->assertTrue(Str::isUlid($a->public_id));
        $this->assertTrue(Str::isUlid($b->public_id));
        $this->assertNotSame($a->public_id, $b->public_id);
        $this->assertSame('harbor_roads', $a->alias);
        $this->assertSame('harbor_roads_2', $b->alias);
    }

    public function test_creating_a_layer_assigns_public_id_and_unique_alias(): void
    {
        $first = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'City Parks',
            'public_id' => null,
            'alias' => null,
        ]);

        $second = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'City Parks',
            'public_id' => null,
            'alias' => null,
        ]);

        $this->assertTrue(Str::isUlid($first->public_id));
        $this->assertTrue(Str::isUlid($second->public_id));
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertSame('city_parks', $first->alias);
        $this->assertSame('city_parks_2', $second->alias);
    }

    public function test_alias_is_unique_per_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $a = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'Shared Name',
            'public_id' => null,
            'alias' => null,
        ]);

        $b = Layer::factory()->create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'name' => 'Shared Name',
            'public_id' => null,
            'alias' => null,
        ]);

        $this->assertSame('shared_name', $a->alias);
        $this->assertSame('shared_name', $b->alias);
    }

    public function test_geo_server_publish_name_prefers_alias(): void
    {
        $layer = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'alias' => 'roads',
            'table_name' => 'import_org'.$this->organization->id.'_roads_abc',
        ]);

        $this->assertSame('roads', $layer->geoServerPublishName());
    }

    public function test_non_admin_json_api_omits_table_name_and_import_org(): void
    {
        $table = 'import_org'.$this->organization->id.'_sensitive_layer';
        $layer = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'name' => 'Sensitive',
            'table_name' => $table,
            'public_id' => null,
            'alias' => null,
        ]);
        $layer->refresh();

        $token = $this->user->createToken('api', ['layers:read'])->plainTextToken;

        $index = $this->withToken($token)->getJson('/api/v1/layers');
        $index->assertOk();
        $payload = $index->getContent();
        $this->assertStringNotContainsString('table_name', $payload);
        $this->assertStringNotContainsString('import_org', $payload);
        $this->assertStringNotContainsString($table, $payload);
        $this->assertArrayNotHasKey('id', $index->json('data.0') ?? []);

        $show = $this->withToken($token)->getJson('/api/v1/layers/'.$layer->public_id);
        $show->assertOk();
        $showPayload = $show->getContent();
        $this->assertStringNotContainsString('table_name', $showPayload);
        $this->assertStringNotContainsString('import_org', $showPayload);
        $this->assertStringNotContainsString($table, $showPayload);
        $show->assertJsonPath('data.public_id', $layer->public_id);
        $show->assertJsonPath('data.alias', $layer->alias);
        $this->assertArrayNotHasKey('id', $show->json('data'));
    }

    public function test_api_v1_resolves_layer_by_public_id_or_alias_not_sequential_id(): void
    {
        $layer = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'public_id' => null,
            'alias' => null,
        ])->fresh();

        $token = $this->user->createToken('api', ['layers:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/layers/'.$layer->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $layer->public_id);

        $this->withToken($token)
            ->getJson('/api/v1/layers/'.$layer->alias)
            ->assertOk()
            ->assertJsonPath('data.public_id', $layer->public_id);

        $this->withToken($token)
            ->getJson('/api/v1/layers/'.$layer->id)
            ->assertNotFound();

        $otherOrg = Organization::factory()->create();
        $otherLayer = Layer::factory()->create([
            'organization_id' => $otherOrg->id,
            'user_id' => User::factory()->create(['organization_id' => $otherOrg->id])->id,
            'public_id' => null,
            'alias' => null,
        ])->fresh();

        $this->withToken($token)
            ->getJson('/api/v1/layers/'.$otherLayer->public_id)
            ->assertNotFound();
    }

    public function test_non_admin_inertia_layer_show_omits_table_name(): void
    {
        $table = 'import_org'.$this->organization->id.'_ui_layer';
        $layer = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'table_name' => $table,
            'public_id' => null,
            'alias' => null,
        ]);

        $response = $this->actingAs($this->user)->get(route('layers.show', $layer));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Layers/Show')
            ->has('layer.public_id')
            ->has('layer.alias')
            ->missing('layer.table_name')
        );

        $this->assertStringNotContainsString($table, $response->getContent());
        $this->assertStringNotContainsString('import_org', $response->getContent());
    }

    public function test_super_admin_can_see_table_name_in_layer_show(): void
    {
        $table = 'import_org'.$this->organization->id.'_diag_layer';
        $layer = Layer::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->superAdmin->id,
            'table_name' => $table,
            'public_id' => null,
            'alias' => null,
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('layers.show', $layer));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Layers/Show')
            ->where('layer.table_name', $table)
        );
    }
}
