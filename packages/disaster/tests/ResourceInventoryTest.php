<?php

namespace Lumina\Disaster\Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\InventoryItem;
use Lumina\Disaster\Models\InventoryLedger;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Models\Volunteer;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Services\InventoryLedgerService;
use Lumina\Disaster\Services\WarehouseMatcher;
use Lumina\Disaster\Support\Enums\InventoryLedgerReason;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;
use Lumina\Disaster\Support\Enums\WarehouseStatus;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class ResourceInventoryTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected ResourceType $waterType;

    protected Warehouse $nearWarehouse;

    protected Warehouse $farWarehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
        $this->seedCatalog();
    }

    public function test_full_request_state_machine_decrements_via_ledger(): void
    {
        $incident = $this->openIncident();

        $create = $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/resource-requests', [
                'incident_id' => $incident->id,
                'resource_type_id' => $this->waterType->id,
                'quantity' => 20,
                'priority' => 'high',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.edxl_json.EDXLResourceMessage.messageContentType', 'RequestResource');

        $requestId = (int) $create->json('data.id');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/resource-requests/{$requestId}/transition", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/resource-requests/{$requestId}/match")
            ->assertOk()
            ->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.assignments.0.warehouse_id', $this->nearWarehouse->id);

        $stockBefore = (int) InventoryItem::query()
            ->where('warehouse_id', $this->nearWarehouse->id)
            ->where('resource_type_id', $this->waterType->id)
            ->value('quantity');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/resource-requests/{$requestId}/transition", ['status' => 'dispatched'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dispatched');

        $stockAfter = (int) InventoryItem::query()
            ->where('warehouse_id', $this->nearWarehouse->id)
            ->where('resource_type_id', $this->waterType->id)
            ->value('quantity');

        $this->assertSame($stockBefore - 20, $stockAfter);
        $this->assertSame(
            1,
            InventoryLedger::query()
                ->where('request_id', $requestId)
                ->where('reason', InventoryLedgerReason::Out)
                ->where('delta', -20)
                ->count(),
        );

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/resource-requests/{$requestId}/transition", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.assignments.0.delivered_at', fn ($v) => $v !== null);

        $request = ResourceRequest::query()->findOrFail($requestId);
        $this->assertSame(ResourceRequestStatus::Delivered, $request->status);
        $this->assertSame('RequestResource', $request->edxl_json['EDXLResourceMessage']['messageContentType'] ?? null);
    }

    public function test_ledger_rejects_negative_stock_and_is_append_only(): void
    {
        $service = app(InventoryLedgerService::class);

        try {
            $service->stockOut($this->nearWarehouse, $this->waterType->id, 10_000, $this->disasterAdmin);
            $this->fail('Expected ValidationException for negative stock');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $entry = $service->stockIn($this->nearWarehouse, $this->waterType->id, 5, $this->disasterAdmin, 'top-up');

        $this->expectException(LogicException::class);
        $entry->update(['delta' => 99]);
    }

    public function test_ledger_cannot_be_deleted(): void
    {
        $entry = app(InventoryLedgerService::class)
            ->stockIn($this->nearWarehouse, $this->waterType->id, 1, $this->disasterAdmin);

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_matcher_picks_nearest_warehouse_with_stock(): void
    {
        $matcher = app(WarehouseMatcher::class);
        $this->assertFalse($matcher->usesPostgis());

        $match = $matcher->bestMatch(
            $this->disasterOrg->id,
            $this->waterType->id,
            10,
            32.88,
            13.19,
        );

        $this->assertNotNull($match);
        $this->assertSame($this->nearWarehouse->id, $match['warehouse']->id);
        $this->assertNotNull($match['distance_m']);
        $this->assertLessThan(
            (float) $matcher->nearestWithStock(
                $this->disasterOrg->id,
                $this->waterType->id,
                10,
                32.88,
                13.19,
                5,
            )[1]['distance_m'],
            (float) $match['distance_m'],
        );
    }

    public function test_permissions_manage_vs_view_vs_dispatch(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $this->assertFalse(Gate::forUser($member)->allows('disaster.manage'));
        $this->assertTrue(Gate::forUser($member)->allows('disaster.view'));
        $this->assertTrue(Gate::forUser($member)->allows('disaster.dispatch'));

        $this->actingAs($member)
            ->postJson('/x/disaster/inventory/types', [
                'name' => 'Blankets',
                'unit' => 'ea',
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson('/x/disaster/inventory/warehouses', [
                'name' => 'Depot B',
                'latitude' => 32.9,
                'longitude' => 13.2,
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson('/x/disaster/inventory/adjust', [
                'warehouse_id' => $this->nearWarehouse->id,
                'resource_type_id' => $this->waterType->id,
                'delta' => 1,
                'reason' => 'in',
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson('/x/disaster/volunteers', ['name' => 'Blocked'])
            ->assertForbidden();

        $this->actingAs($member)
            ->get('/x/disaster/inventory')
            ->assertOk();

        $this->actingAs($member)
            ->get('/x/disaster/resource-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Disaster/ResourceRequests'));

        $incident = $this->openIncident();
        $this->actingAs($member)
            ->postJson('/x/disaster/resource-requests', [
                'incident_id' => $incident->id,
                'resource_type_id' => $this->waterType->id,
                'quantity' => 3,
            ])
            ->assertCreated();
    }

    public function test_inventory_and_volunteers_console_pages(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Inventory')
                ->has('warehouses', 2)
                ->has('resource_types', 1)
                ->where('can_manage', true)
            );

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/volunteers', [
                'name' => 'Sara Ali',
                'skills' => 'first aid, radio',
                'availability' => 'weekends',
                'organization_name' => 'Red Crescent',
            ])
            ->assertCreated()
            ->assertJsonPath('data.skills.0', 'first aid');

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/volunteers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Volunteers')
                ->has('volunteers', 1)
            );

        $this->assertSame(1, Volunteer::query()->count());
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $incident = $this->openIncident();
        $request = ResourceRequest::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'requester_user_id' => $this->disasterAdmin->id,
            'incident_id' => $incident->id,
            'resource_type_id' => $this->waterType->id,
            'quantity' => 5,
            'status' => ResourceRequestStatus::Requested,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/resource-requests/{$request->id}/transition", ['status' => 'dispatched'])
            ->assertStatus(422);
    }

    public function test_cross_tenant_resource_request_hidden(): void
    {
        $otherOrg = Organization::factory()->create();
        $other = User::factory()->create(['organization_id' => $otherOrg->id]);
        $incident = $this->openIncident();
        $request = ResourceRequest::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'requester_user_id' => $this->disasterAdmin->id,
            'incident_id' => $incident->id,
            'resource_type_id' => $this->waterType->id,
            'quantity' => 5,
            'status' => ResourceRequestStatus::Requested,
        ]);

        $this->actingAs($other)
            ->postJson("/x/disaster/resource-requests/{$request->id}/transition", ['status' => 'approved'])
            ->assertNotFound();
    }

    protected function seedCatalog(): void
    {
        $this->waterType = ResourceType::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'name' => 'Bottled water',
            'category' => 'relief',
            'capability' => 'hydration',
            'unit' => 'case',
        ]);

        $this->nearWarehouse = Warehouse::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'name' => 'Near depot',
            'status' => WarehouseStatus::Active,
            'geom' => GeometryWriter::pointFromCoordinates(32.881, 13.191),
        ]);

        $this->farWarehouse = Warehouse::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'name' => 'Far depot',
            'status' => WarehouseStatus::Active,
            'geom' => GeometryWriter::pointFromCoordinates(33.50, 14.50),
        ]);

        $ledger = app(InventoryLedgerService::class);
        $ledger->stockIn($this->nearWarehouse, $this->waterType->id, 100, $this->disasterAdmin, 'initial');
        $ledger->stockIn($this->farWarehouse, $this->waterType->id, 100, $this->disasterAdmin, 'initial');
    }

    protected function openIncident(): Incident
    {
        $response = $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/incidents', [
                'external_id' => 'INC-RES-'.uniqid(),
                'title' => 'Flood sector 3',
                'status' => 'open',
                'priority' => 2,
                'latitude' => 32.88,
                'longitude' => 13.19,
            ])
            ->assertCreated();

        return Incident::query()->findOrFail($response->json('data.id'));
    }
}
