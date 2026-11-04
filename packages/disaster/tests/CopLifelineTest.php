<?php

namespace Lumina\Disaster\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Support\Enums\Lifeline;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class CopLifelineTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_lifeline_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('dm_lifeline_components'));
        $this->assertTrue(Schema::hasTable('dm_lifeline_status'));
        $this->assertTrue(Schema::hasColumn('dm_lifeline_components', 'lifeline'));
        $this->assertTrue(Schema::hasColumn('dm_lifeline_status', 'status'));
    }

    public function test_manifest_lists_lifeline_tables(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
        )['extra']['lumina'];

        $this->assertContains('dm_lifeline_components', $manifest['data']['tables']);
        $this->assertContains('dm_lifeline_status', $manifest['data']['tables']);

        $responseKeys = array_column(
            array_merge(...array_map(
                static fn (array $section) => $section['items'] ?? [],
                $manifest['module']['sections'] ?? [],
            )),
            'key',
        );
        $this->assertContains('disaster-cop', $responseKeys);
    }

    public function test_lifeline_rollup_uses_worst_component_status(): void
    {
        $energyA = LifelineComponent::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'lifeline' => Lifeline::Energy,
            'name' => 'Substation North',
        ]);
        $energyB = LifelineComponent::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'lifeline' => Lifeline::Energy,
            'name' => 'Grid feed B',
        ]);

        LifelineStatus::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'component_id' => $energyA->id,
            'status' => LifelineComponentStatus::Stable,
            'reported_by' => $this->disasterAdmin->id,
            'reported_at' => now()->subHour(),
        ]);
        LifelineStatus::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'component_id' => $energyB->id,
            'status' => LifelineComponentStatus::Impacted,
            'reason' => 'Transformer offline',
            'reported_by' => $this->disasterAdmin->id,
            'reported_at' => now(),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/cop')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Cop')
                ->where('avl_included', true)
                ->has('lifelines', 8)
                ->where('lifelines.3.lifeline', Lifeline::Energy->value)
                ->where('lifelines.3.status', LifelineComponentStatus::Impacted->value)
                ->where('lifelines.3.color', 'red')
                ->where('lifelines.3.component_count', 2)
            );
    }

    /**
     * Soft budget: COP page should stay under ~35 queries with a seeded org
     * (eager-loaded latestStatus; no per-component N+1). Raise only with justification.
     */
    public function test_cop_page_query_count_soft_budget(): void
    {
        Incident::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'external_id' => 'COP-1',
            'title' => 'Flood sector 3',
            'status' => 'open',
            'opened_at' => now(),
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'UNIT-1',
            'resource_class' => 'ems',
            'resource_status' => '01_Available',
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        foreach (Lifeline::cases() as $i => $lifeline) {
            $component = LifelineComponent::query()->create([
                'organization_id' => $this->disasterOrg->id,
                'lifeline' => $lifeline,
                'name' => $lifeline->label().' core',
            ]);
            LifelineStatus::query()->create([
                'organization_id' => $this->disasterOrg->id,
                'component_id' => $component->id,
                'status' => $i % 2 === 0 ? LifelineComponentStatus::Stable : LifelineComponentStatus::AtRisk,
                'reported_by' => $this->disasterAdmin->id,
                'reported_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/cop')
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            35,
            $queryCount,
            "COP soft budget exceeded: {$queryCount} queries (target ≤ 35).",
        );
    }

    public function test_member_and_field_can_view_cop_with_avl_dispatcher_can_manage_status(): void
    {
        $member = User::factory()->create(['organization_id' => $this->disasterOrg->id]);
        $field = User::factory()->create(['organization_id' => $this->disasterOrg->id]);

        $component = LifelineComponent::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'lifeline' => Lifeline::Communications,
            'name' => 'EOC radio',
        ]);
        LifelineStatus::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'component_id' => $component->id,
            'status' => LifelineComponentStatus::Unknown,
            'reported_by' => $this->disasterAdmin->id,
            'reported_at' => now()->subDay(),
        ]);

        AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'CAD-SECRET',
            'resource_class' => 'law',
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        // Member (org member gates include disaster.view + disaster.telemetry).
        $this->actingAs($member)
            ->get('/x/disaster/cop')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Cop')
                ->where('avl_included', true)
                ->has('avl.units', 1)
                ->where('can_manage', false)
                ->where('can_field', true)
            );

        // Field can report status (disaster.field gate).
        $this->actingAs($field)
            ->postJson('/x/disaster/cop/status', [
                'component_id' => $component->id,
                'status' => LifelineComponentStatus::AtRisk->value,
                'reason' => 'Intermittent link',
            ])
            ->assertCreated();

        // Member cannot create components (manage only).
        $this->actingAs($member)
            ->postJson('/x/disaster/cop/components', [
                'lifeline' => Lifeline::WaterSystems->value,
                'name' => 'Plant A',
            ])
            ->assertForbidden();

        // Dispatcher/admin (manage) can create components.
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/cop/components', [
                'lifeline' => Lifeline::WaterSystems->value,
                'name' => 'Plant A',
                'initial_status' => LifelineComponentStatus::Stable->value,
            ])
            ->assertCreated();
    }

    public function test_public_portal_never_exposes_avl_or_cop_internals(): void
    {
        AvlUnit::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'unit_id' => 'HIDDEN',
            'resource_class' => 'ems',
            'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);

        $issued = PublicToken::issue($this->disasterOrg->id, 'public');

        $feed = $this->getJson('/dm/public/'.$issued['plain'].'/feed')
            ->assertOk()
            ->json();

        $this->assertFalse($feed['avl_included']);
        $this->assertArrayNotHasKey('avl', $feed);
        $this->assertArrayNotHasKey('avl_units', $feed);
        $this->assertArrayNotHasKey('lifelines', $feed);

        $this->get('/x/disaster/cop')->assertRedirect();
    }

    public function test_cop_export_returns_pdf_or_printable_html(): void
    {
        $response = $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/cop/export');

        $response->assertOk();
        $contentType = (string) $response->headers->get('content-type');
        $this->assertTrue(
            str_contains($contentType, 'pdf') || str_contains($contentType, 'text/html'),
            "Unexpected content type: {$contentType}",
        );
    }
}
