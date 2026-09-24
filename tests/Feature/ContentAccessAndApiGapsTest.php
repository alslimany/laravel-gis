<?php

namespace Tests\Feature;

use App\Models\ContentAccess;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentAccessAndApiGapsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $peer;

    protected Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Role::create(['name' => 'admin', 'description' => 'Admin']);
        $editor = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $org = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $org->id]);
        $this->owner->roles()->attach($admin);

        $this->peer = User::factory()->create(['organization_id' => $org->id]);
        $this->peer->roles()->attach($editor);

        $project = Project::factory()->create(['organization_id' => $org->id]);
        $this->layer = Layer::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $org->id,
            'project_id' => $project->id,
        ]);

        ContentAccess::create([
            'content_type' => 'layer',
            'content_id' => $this->layer->id,
            'visibility' => 'private',
            'organization_id' => $org->id,
        ]);
    }

    public function test_private_layer_denied_to_peer_on_session_route(): void
    {
        $this->actingAs($this->peer)
            ->get(route('layers.show', $this->layer))
            ->assertForbidden();
    }

    public function test_owner_can_view_private_layer(): void
    {
        $this->assertTrue($this->owner->can('view', $this->layer));
        $this->assertFalse($this->peer->can('view', $this->layer));
    }

    public function test_sanctum_feature_create_dispatches_webhook(): void
    {
        Http::fake();

        Webhook::create([
            'organization_id' => $this->owner->organization_id,
            'name' => 'Test hook',
            'url' => 'https://hooks.example.test/gis',
            'events' => ['feature.created'],
            'secret' => 'test-secret',
            'is_active' => true,
        ]);

        // Private layer would block peer; use org visibility for API write test.
        ContentAccess::where('content_id', $this->layer->id)->update([
            'visibility' => 'organization',
        ]);

        Sanctum::actingAs($this->owner);

        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostGIS required for feature create webhook test.');
        }

        $table = $this->layer->table_name;
        \Illuminate\Support\Facades\DB::statement("
            CREATE TABLE IF NOT EXISTS {$table} (
                id serial PRIMARY KEY,
                name text,
                geom geometry(Point, 4326)
            )
        ");

        $response = $this->postJson("/api/v1/layers/{$this->layer->id}/features", [
            'wkt' => 'POINT(1 2)',
            'attributes' => ['name' => 'hooked'],
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/gis');
    }
}
