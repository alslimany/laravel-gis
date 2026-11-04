<?php

namespace Lumina\Disaster\Tests;

use App\Models\OrganizationPlugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Models\EvacuationOrderEvent;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class EvacuationOrderTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected User $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();

        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['description' => 'Administrator'],
        );
        $this->secondAdmin = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $this->secondAdmin->roles()->attach($adminRole->id, ['organization_id' => $this->disasterOrg->id]);
    }

    public function test_permission_matrix_for_approve_evacuation_gate(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->assertTrue(Gate::forUser($this->disasterAdmin)->allows('disaster.approve_evacuation'));
        $this->assertTrue(Gate::forUser($this->secondAdmin)->allows('disaster.approve_evacuation'));
        $this->assertFalse(Gate::forUser($member)->allows('disaster.approve_evacuation'));
        $this->assertTrue(Gate::forUser($this->disasterAdmin)->allows('disaster.manage'));
        $this->assertFalse(Gate::forUser($member)->allows('disaster.manage'));
        // approve_evacuation is separate from manage but both require org admin.
        $this->assertTrue(Gate::has('disaster.approve_evacuation'));
        $this->assertTrue(Gate::has('disaster.manage'));
    }

    public function test_console_crud_creates_draft_with_zone_and_links(): void
    {
        $shelter = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'SH-1',
            'type' => 'shelter',
            'name' => 'School Shelter',
            'capacity' => 200,
            'remaining_capacity' => 200,
        ]);
        $corridor = EvacCorridor::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'name' => 'Coastal Route',
            'shelter_site_id' => $shelter->id,
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/evacuation-orders', [
                'text_en' => 'Evacuate Zone A immediately.',
                'text_ar' => 'إخلاء المنطقة أ فوراً.',
                'estimated_population' => 1500,
                'is_official' => true,
                'corridor_ids' => [$corridor->id],
                'shelter_site_ids' => [$shelter->id],
                'geojson' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[13.18, 32.87], [13.20, 32.87], [13.20, 32.89], [13.18, 32.89], [13.18, 32.87]]],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_official', true)
            ->assertJsonPath('data.corridor_ids.0', $corridor->id)
            ->assertJsonPath('data.shelter_site_ids.0', $shelter->id);

        $order = EvacuationOrder::query()->first();
        $this->assertNotNull($order);
        $this->assertSame((int) $this->disasterAdmin->id, (int) $order->authored_by);
        $this->assertSame(1, EvacuationOrderEvent::query()->where('action', 'created')->count());

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/evacuation-orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/EvacuationOrders')
                ->has('orders', 1)
            );

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/evacuation-orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/EvacuationOrderShow')
                ->where('order.id', $order->id)
                ->where('order.status', 'draft')
            );
    }

    public function test_each_happy_path_transition_and_audit_trail(): void
    {
        $order = $this->draftOrder($this->disasterAdmin);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::PendingApproval->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $this->actingAs($this->secondAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Approved->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $this->secondAdmin->id);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Issued->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::InProgress->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Completed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $actions = EvacuationOrderEvent::query()
            ->where('evacuation_order_id', $order->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(
            ['submit_for_approval', 'approve', 'issue', 'start_execution', 'complete'],
            $actions,
        );
    }

    public function test_author_cannot_self_approve(): void
    {
        $order = $this->draftOrder($this->disasterAdmin);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::PendingApproval->value,
            ])
            ->assertOk();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Approved->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(
            EvacuationOrderStatus::PendingApproval,
            $order->fresh()->status,
        );
    }

    public function test_cannot_issue_without_approval(): void
    {
        $order = $this->draftOrder($this->disasterAdmin);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Issued->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::PendingApproval->value,
            ])
            ->assertOk();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Issued->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_member_cannot_approve_even_when_not_author(): void
    {
        $order = $this->draftOrder($this->disasterAdmin);
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::PendingApproval->value,
            ])
            ->assertOk();

        $this->actingAs($member)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Approved->value,
            ])
            ->assertForbidden();
    }

    public function test_execution_updates_shelter_remaining_capacity(): void
    {
        $shelter = CriticalSite::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'site_id' => 'SH-CAP',
            'type' => 'shelter',
            'name' => 'Capacity Shelter',
            'capacity' => 100,
            'remaining_capacity' => 100,
        ]);

        $order = $this->draftOrder($this->disasterAdmin, [
            'shelter_site_ids' => [$shelter->id],
        ]);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => 'pending_approval',
            ])->assertOk();
        $this->actingAs($this->secondAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => 'approved',
            ])->assertOk();
        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => 'issued',
            ])->assertOk();

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/execution", [
                'evacuated_count' => 40,
                'unaccounted_count' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.evacuated_count', 40);

        $this->assertSame(60, (int) $shelter->fresh()->remaining_capacity);
    }

    public function test_official_mode_drops_advisory_copy_for_official_orders(): void
    {
        $row = OrganizationPlugin::query()
            ->where('organization_id', $this->disasterOrg->id)
            ->where('plugin_key', 'disaster')
            ->firstOrFail();
        $settings = is_array($row->settings) ? $row->settings : [];
        $row->settings = array_merge($settings, ['is_official_authority' => true]);
        $row->save();

        $advisory = AdvisoryCopy::forUi(
            organizationId: (int) $this->disasterOrg->id,
            isOfficialContent: true,
        );

        $this->assertSame('', $advisory['advisory']);
        $this->assertTrue($advisory['is_official_mode']);

        $kept = AdvisoryCopy::forUi(
            organizationId: (int) $this->disasterOrg->id,
            isOfficialContent: false,
        );
        $this->assertNotSame('', $kept['advisory']);
        $this->assertFalse($kept['is_official_mode']);
    }

    public function test_cancel_from_draft(): void
    {
        $order = $this->draftOrder($this->disasterAdmin);

        $this->actingAs($this->disasterAdmin)
            ->postJson("/x/disaster/evacuation-orders/{$order->id}/transition", [
                'status' => EvacuationOrderStatus::Cancelled->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function draftOrder(User $author, array $overrides = []): EvacuationOrder
    {
        return EvacuationOrder::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'status' => EvacuationOrderStatus::Draft,
            'authored_by' => $author->id,
            'created_by' => $author->id,
            'updated_by' => $author->id,
            'text_en' => 'Draft evacuation order',
            'text_ar' => null,
            'is_official' => false,
            'evacuated_count' => 0,
            'unaccounted_count' => 0,
            ...$overrides,
        ]);
    }
}
