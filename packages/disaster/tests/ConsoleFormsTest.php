<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

class ConsoleFormsTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_hazards_inertia_includes_layers_and_update_url(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/hazards')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Hazards')
                ->has('layers')
                ->has('update_url')
                ->has('settings')
                ->where('can_manage', true)
            );
    }

    public function test_assets_inertia_includes_store_urls_and_enums(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Assets')
                ->has('medical_store_url')
                ->has('staging_store_url')
                ->has('critical_store_url')
                ->has('condition_store_url')
                ->has('generator_statuses')
                ->has('critical_site_types')
                ->has('condition_grades')
                ->has('overdue_inspections')
            );
    }

    public function test_medical_store_redirects_for_inertia_form_post(): void
    {
        $response = $this->actingAs($this->disasterAdmin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post('/x/disaster/medical-facilities', [
                'facility_id' => 'MF-CONSOLE',
                'name' => 'Console Hospital',
                'latitude' => 32.88,
                'longitude' => 13.19,
            ]);

        $response->assertRedirect(route('disaster.assets.index'));
        $this->assertDatabaseHas('dm_medical_facilities', [
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'MF-CONSOLE',
        ]);
    }

    public function test_public_tokens_page_receives_plain_token_flash(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->post('/x/disaster/public-tokens', ['label' => 'Test feed'])
            ->assertRedirect(route('disaster.public-tokens.index'));

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/public-tokens')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/PublicTokens')
                ->has('issue_token_url')
            );
    }

    public function test_exposure_page_lists_summaries_after_compute(): void
    {
        MedicalFacility::query()->create([
            'organization_id' => $this->disasterOrg->id,
            'facility_id' => 'MF-EXP',
            'name' => 'Exposure Hospital',
            'geom' => GeometryWriter::pointFromCoordinates(32.881, 13.191),
        ]);

        $this->actingAs($this->disasterAdmin)
            ->post('/x/disaster/exposure/compute', [
                'hazard_key' => 'floodplain',
                'latitude' => 32.88,
                'longitude' => 13.19,
                'radius_m' => 5000,
                'sync' => true,
            ])
            ->assertRedirect(route('disaster.exposure.page'));

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/exposure')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disaster/Exposure')
                ->has('summaries')
                ->has('ranked')
            );
    }
}
