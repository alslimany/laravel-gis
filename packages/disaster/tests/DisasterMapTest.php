<?php

namespace Lumina\Disaster\Tests;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Publishing\AssetLayerPublisher;
use Lumina\Disaster\Publishing\OpsLayerPublisher;
use Lumina\Disaster\Support\DisasterMapConfig;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\GeometryWriter;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Lumina\EarlyWarning\Publishing\RiskLayerPublisher;
use Tests\TestCase;

class DisasterMapTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    private Organization $otherOrg;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
        $this->disasterAdmin->forceFill(['preferred_locale' => 'ar'])->save();
        $this->app->register(EarlyWarningServiceProvider::class);
        $this->artisan('migrate', ['--path' => EarlyWarningServiceProvider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        $this->otherOrg = Organization::factory()->create();

        foreach ([$this->disasterOrg, $this->otherOrg] as $org) {
            $this->seedOps($org);
        }

        $this->ids = $this->publish($this->disasterOrg);
        $this->publish($this->otherOrg);
    }

    private function seedOps(Organization $org): void
    {
        Incident::query()->create([
            'organization_id' => $org->id, 'external_id' => 'INC-'.$org->id, 'title' => 'Flood', 'status' => 'open',
            'priority' => 1, 'opened_at' => now(), 'geom' => GeometryWriter::pointFromCoordinates(32.88, 13.19),
        ]);
        Closure::query()->create([
            'organization_id' => $org->id, 'external_id' => 'CL-'.$org->id, 'closure_type' => 'road', 'severity' => 'major',
            'geom' => GeometryWriter::lineFromGeoJson(['type' => 'LineString', 'coordinates' => [[13.18, 32.87], [13.20, 32.89]]]),
        ]);
        CriticalSite::query()->create([
            'organization_id' => $org->id, 'site_id' => 'CS-'.$org->id, 'name' => 'Dam',
            'type' => CriticalSiteType::cases()[0]->value, 'geom' => GeometryWriter::pointFromCoordinates(32.9, 13.2),
        ]);
    }

    /** @return array<string, int> */
    private function publish(Organization $org): array
    {
        $userId = User::factory()->create(['organization_id' => $org->id])->id;
        $assets = app(AssetLayerPublisher::class)->publish($org, false, $userId);
        $ops = app(OpsLayerPublisher::class)->publish($org, false, $userId);
        $risk = app(RiskLayerPublisher::class)->publish($org, syncGeoServer: false, userId: $userId);

        return [
            'risk' => $risk['layer']->id,
            'assets' => $assets['layer']->id,
            'incidents' => $ops['incidents']['layer']->id,
            'closures' => $ops['closures']['layer']->id,
        ];
    }

    public function test_ops_layers_are_org_scoped_views_with_colour_renderers(): void
    {
        $incidents = Layer::query()->findOrFail($this->ids['incidents']);
        $closures = Layer::query()->findOrFail($this->ids['closures']);
        $assets = Layer::query()->findOrFail($this->ids['assets']);

        $this->assertSame(OpsLayerPublisher::incidentsTable($this->disasterOrg->id), $incidents->table_name);
        $this->assertSame('priority', $incidents->style_config['field']);
        $this->assertSame('severity', $closures->style_config['field']);
        $this->assertSame('unique', $assets->style_config['renderer']);
        $this->assertSame('condition_grade', $assets->style_config['field']);
        $this->assertSame(1, $incidents->feature_count);
        $this->assertSame(1, $closures->feature_count);
        $this->assertSame('LineString', $closures->geometry_type);
    }

    public function test_cop_and_assets_pages_include_only_this_orgs_layers(): void
    {
        $other = Layer::query()->where('organization_id', $this->otherOrg->id)->pluck('id')->all();
        $this->assertCount(4, $other);
        $expected = [$this->ids['risk'], $this->ids['assets'], $this->ids['incidents'], $this->ids['closures']];

        foreach (['/x/disaster/cop' => 'Disaster/Cop', '/x/disaster/assets' => 'Disaster/Assets'] as $url => $component) {
            $this->actingAs($this->disasterAdmin)->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->has('org_map.layers', 4)
                    ->where('org_map.layers', function ($layers) use ($expected, $other) {
                        $ids = collect($layers)->pluck('id')->all();

                        return $ids === $expected && array_intersect($ids, $other) === [];
                    })
                    ->where('org_map.layers.2.title', 'البلاغات')
                    ->where('org_map.layers.2.legend.0.label', 'أولوية 1 (الأعلى)')
                    ->where('org_map.layers.3.legend.0.label', 'كبير')
                    ->where('org_map.layers.2.mvt_url', "/api/layers/{$this->ids['incidents']}/tiles/{z}/{x}/{y}.mvt")
                    // Bounds come from PostGIS; the sqlite test DB has no extent, so only the key is checked.
                    ->has('org_map.bounds')
                );
        }
    }

    public function test_risk_layer_is_per_org_table_and_config_uses_own_org(): void
    {
        $a = Layer::query()->findOrFail($this->ids['risk']);
        $b = Layer::query()->where('organization_id', $this->otherOrg->id)
            ->where('metadata->kind', 'basin-risk')->firstOrFail();

        $this->assertSame('ew_basin_risk_'.$this->disasterOrg->id, $a->table_name);
        $this->assertSame('ew_basin_risk_'.$this->otherOrg->id, $b->table_name);
        $this->assertNotSame($a->table_name, $b->table_name);

        $config = DisasterMapConfig::forOrganization($this->disasterOrg->id);
        $this->assertSame($a->id, $config['layers'][0]['id']);
        $this->assertNotContains($b->id, array_column($config['layers'], 'id'));
    }

    public function test_other_orgs_layer_tiles_are_forbidden(): void
    {
        // Tile bytes need PostGIS (verified against the live demo DB); here only the org check.
        $otherId = Layer::query()->where('organization_id', $this->otherOrg->id)
            ->where('table_name', OpsLayerPublisher::incidentsTable($this->otherOrg->id))->value('id');
        $this->actingAs($this->disasterAdmin)
            ->get("/api/layers/{$otherId}/tiles/8/137/103.mvt")
            ->assertForbidden();
    }
}
