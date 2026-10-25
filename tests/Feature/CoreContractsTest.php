<?php

namespace Tests\Feature;

use App\Contracts\LayerCatalog;
use App\Contracts\LayerPublisher;
use App\Events\LayerCreated;
use App\Events\LayerDeleted;
use App\Events\LayerUpdated;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Project Guide §6 — Core contracts plugins must use. */
class CoreContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_layer_catalog_scopes_by_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $orgA->id]);

        $layerA = Layer::factory()->create([
            'organization_id' => $orgA->id,
            'user_id' => $user->id,
            'name' => 'Basins A',
        ]);
        Layer::factory()->create([
            'organization_id' => $orgB->id,
            'user_id' => User::factory()->create(['organization_id' => $orgB->id])->id,
            'name' => 'Basins B',
        ]);

        $catalog = app(LayerCatalog::class);

        $this->assertNotNull($catalog->findForOrganization($orgA->id, $layerA->id));
        $this->assertNull($catalog->findForOrganization($orgB->id, $layerA->id));
        $this->assertCount(1, $catalog->forOrganization($orgA->id));
    }

    public function test_layer_publisher_upserts_without_geoserver(): void
    {
        $org = Organization::factory()->create();
        User::factory()->create(['organization_id' => $org->id]);

        $result = app(LayerPublisher::class)->upsertSqlViewLayer(
            $org,
            [
                'table_name' => 'ew_basin_risk',
                'name' => 'Basin flood risk (advisory)',
                'geometry_type' => 'MultiPolygon',
                'feature_count' => 0,
                'metadata' => ['plugin' => 'early-warning'],
            ],
            syncGeoServer: false,
        );

        $this->assertFalse($result['published']);
        $this->assertDatabaseHas('layers', [
            'organization_id' => $org->id,
            'table_name' => 'ew_basin_risk',
            'name' => 'Basin flood risk (advisory)',
        ]);
    }

    public function test_layer_model_dispatches_lifecycle_events(): void
    {
        Event::fake([LayerCreated::class, LayerUpdated::class, LayerDeleted::class]);

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $layer = Layer::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);
        Event::assertDispatched(LayerCreated::class);

        $layer->update(['name' => 'Renamed']);
        Event::assertDispatched(LayerUpdated::class);

        $layer->delete();
        Event::assertDispatched(LayerDeleted::class);
    }

    public function test_organization_context_reads_authenticated_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->actingAs($user);

        $ctx = app(OrganizationContext::class);
        $this->assertSame($org->id, $ctx->requireOrganizationId());
        $this->assertFalse($ctx->isOrgAdmin());
    }
}
