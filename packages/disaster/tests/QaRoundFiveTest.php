<?php

namespace Lumina\Disaster\Tests;

use App\Models\Layer;
use App\Models\LayerRelationship;
use App\Models\User;
use App\SolutionPacks\SolutionPack;
use App\Support\EnumLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\Enums\TaskPriority;
use Lumina\Disaster\Support\Enums\TaskStatus;
use Lumina\Disaster\Support\Enums\WorkspaceMemberRole;
use Lumina\Disaster\Support\EventLogLabel;
use Lumina\Disaster\Tests\Concerns\InstallsDisasterPlugin;
use Tests\TestCase;

/** PM QA round 5: EVAC create route, Arabic timeline labels and errors, Arabic pack counts. */
class QaRoundFiveTest extends TestCase
{
    use InstallsDisasterPlugin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDisasterPlugin();
    }

    public function test_evacuation_create_url_redirects_instead_of_500(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/evacuation-orders/create')
            ->assertRedirect();

        $this->actingAs($this->disasterAdmin)
            ->get('/x/disaster/evacuation-orders/not-a-number')
            ->assertNotFound();
    }

    public function test_evacuation_order_accepts_drawn_polygon(): void
    {
        $this->actingAs($this->disasterAdmin)
            ->postJson('/x/disaster/evacuation-orders', [
                'text_ar' => 'إخلاء فوري.',
                'geojson' => ['type' => 'Polygon', 'coordinates' => [[[13.18, 32.87], [13.2, 32.87], [13.2, 32.89], [13.18, 32.87]]]],
            ])
            ->assertCreated();
    }

    public function test_timeline_event_types_and_statuses_are_arabic(): void
    {
        app()->setLocale('ar');
        $this->assertSame('توفير موارد للطلب', EventLogLabel::type('resource_request_matched'));
        $body = EventLogLabel::body('resource_request_matched', 'Resource request #2: approved → matched', [
            'request_id' => 2, 'from' => 'approved', 'to' => 'matched',
        ]);
        $this->assertStringContainsString('معتمد', (string) $body);
        $this->assertStringNotContainsString('approved', (string) $body);
    }

    public function test_pack_counts_use_arabic_plural_forms(): void
    {
        app()->setLocale('ar');
        $this->assertSame('طبقتان', trans_choice('packs.count.layers', 2));
        $this->assertSame('5 طبقات', trans_choice('packs.count.layers', 5));
        $this->assertSame('12 طبقة', trans_choice('packs.count.layers', 12));
        $this->assertSame('طبقة واحدة', trans_choice('packs.count.layers', 1));
        $this->assertSame('سير العمل (3 خطوات)', trans_choice('packs.workflow_steps', 3));

        $pack = collect(SolutionPack::all())->first(fn ($p) => $p->slug() === 'aec-construction');
        $this->assertNotNull($pack);
        $this->assertSame('الهندسة والإنشاءات', $pack->displayName());
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $pack->contentsSummary());

        app()->setLocale('en');
        $this->assertSame('2 layers', trans_choice('packs.count.layers', 2));
        $this->assertSame('1 layer', trans_choice('packs.count.layers', 1));
    }

    public function test_workflow_transition_errors_are_translated(): void
    {
        foreach (['disaster.rr.cannot_transition', 'disaster.rr.only_approved_match', 'disaster.damage.cannot_transition', 'disaster.damage.max_photos'] as $key) {
            app()->setLocale('ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __($key, ['from' => 'x', 'to' => 'y', 'count' => 3]));
        }
    }

    public function test_incident_page_badges_have_arabic_labels(): void
    {
        $enums = [
            TaskStatus::class,
            TaskPriority::class,
            IncidentPlanStatus::class,
            ResourceStatus::class,
            IncidentRoleType::class,
            WorkspaceMemberRole::class,
        ];
        $values = ['open', 'closed', 'flood', 'landslide', 'rescue', 'power'];
        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $values[] = $case->value;
            }
        }

        foreach (array_unique($values) as $value) {
            $label = EnumLabel::for($value, 'ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $label, "enum.{$value} has no Arabic label");
        }
        app()->setLocale('ar');
        foreach (range(1, 5) as $priority) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __("disaster.priority.{$priority}"));
        }
    }

    public function test_relationship_pages_send_no_table_names_to_org_admins(): void
    {
        $parent = $this->tableLayer('Parcels');
        $child = $this->tableLayer('Inspections');
        Schema::table($child->table_name, fn ($t) => $t->unsignedBigInteger('parcel_id')->nullable());
        LayerRelationship::create([
            'layer_id' => $parent->id, 'related_layer_id' => $child->id, 'name' => 'Parcel inspections', 'foreign_key' => 'parcel_id',
        ]);
        $this->assertFalse((bool) $this->disasterAdmin->is_super_admin);

        foreach (["/layers/{$parent->id}/relationships", route('relationships.builder')] as $url) {
            $response = $this->actingAs($this->disasterAdmin)->get($url)->assertOk();
            // Page data only: the shared translation catalog legitimately has keys like layers.table_name.
            $props = json_encode(Arr::except($response->viewData('page')['props'], ['translations', 'fallbackTranslations']));
            $this->assertStringNotContainsString('table_name', (string) $props, $url);
            $this->assertStringNotContainsString($child->table_name, (string) $props, $url);
        }

        $json = $this->actingAs($this->disasterAdmin)
            ->getJson(route('api.layers.relationships.index', $parent))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('table_name', (string) $json);
    }

    public function test_plugin_layers_carry_description_key_and_backfill_is_safe(): void
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->disasterAdmin->id,
            'organization_id' => $this->disasterAdmin->organization_id,
            'project_id' => null,
            'description' => 'Org-scoped incident points. Decision support only.',
            'metadata' => ['plugin' => 'disaster', 'kind' => 'disaster-incidents', 'name_ar' => 'البلاغات'],
        ]);
        $user = Layer::factory()->create([
            'user_id' => $this->disasterAdmin->id,
            'organization_id' => $this->disasterAdmin->organization_id,
            'project_id' => null,
            'metadata' => ['source' => 'csv'],
        ]);

        $migration = require base_path('database/migrations/2026_11_01_150000_backfill_layer_description_keys.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame('layers.desc.incidents', $layer->fresh()->metadata['description_key']);
        $this->assertSame('البلاغات', $layer->fresh()->metadata['name_ar']);
        $this->assertSame(['source' => 'csv'], $user->fresh()->metadata);

        foreach (['layers.desc.incidents', 'layers.desc.closures', 'layers.desc.assets', 'layers.desc.basin_risk'] as $key) {
            app()->setLocale('ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __($key));
        }
        foreach ([
            base_path('packages/disaster/src/Publishing/OpsLayerPublisher.php') => ['layers.desc.incidents', 'layers.desc.closures'],
            base_path('packages/disaster/src/Publishing/AssetLayerPublisher.php') => ['layers.desc.assets'],
            base_path('packages/early-warning/src/Publishing/RiskLayerPublisher.php') => ['layers.desc.basin_risk'],
        ] as $file => $keys) {
            foreach ($keys as $key) {
                $this->assertStringContainsString("'description_key' => '{$key}'", (string) file_get_contents($file));
            }
        }
    }

    public function test_new_events_carry_title_in_payload(): void
    {
        app()->setLocale('ar');
        $body = EventLogLabel::body('task_created', 'Task created: Old', ['task_id' => 1, 'title' => 'فتح الطريق']);
        $this->assertStringContainsString('فتح الطريق', (string) $body);
        $legacy = EventLogLabel::body('incident_opened', 'Incident opened: Flood W26', ['status' => 'open']);
        $this->assertStringContainsString('Flood W26', (string) $legacy);
        $this->assertStringNotContainsString('Incident opened', (string) $legacy);
    }

    private function tableLayer(string $name): Layer
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->disasterAdmin->id,
            'organization_id' => $this->disasterAdmin->organization_id,
            'project_id' => null,
            'geometry_type' => null,
            'name' => $name,
            'table_name' => 'layer_qa5_'.uniqid(),
        ]);
        Schema::create($layer->table_name, function ($table) {
            $table->increments('id');
            $table->string('label')->nullable();
        });

        return $layer;
    }

    public function test_layer_pages_hide_internal_names_from_org_admins_but_not_super_admins(): void
    {
        $layer = $this->tableLayer('Closures');
        $layer->forceFill(['geoserver_workspace' => 'org_qa5', 'geoserver_layer_name' => 'dm_org_qa5_view'])->save();
        $secrets = [$layer->table_name, 'org_qa5', 'dm_org_qa5_view'];

        $urls = ["/layers/{$layer->id}", "/layers/{$layer->id}/edit", "/layers/{$layer->id}/fields", "/layers/{$layer->id}/attributes", '/layers'];
        foreach ($urls as $url) {
            $response = $this->actingAs($this->disasterAdmin)->get($url)->assertOk();
            $props = (string) json_encode(Arr::except($response->viewData('page')['props'], ['translations', 'fallbackTranslations']));
            foreach ([...$secrets, '"table_name"', '"geoserver_workspace"', '"geoserver_layer_name"'] as $needle) {
                $this->assertStringNotContainsString($needle, $props, "{$url} leaks {$needle}");
            }
        }

        $super = User::factory()->create(['organization_id' => $this->disasterAdmin->organization_id, 'is_super_admin' => true]);
        $props = (string) json_encode($this->actingAs($super)->get("/layers/{$layer->id}")->assertOk()->viewData('page')['props']['layer']);
        $this->assertStringContainsString($layer->table_name, $props);
    }

    public function test_form_field_types_and_project_page_strings_are_arabic(): void
    {
        app()->setLocale('ar');
        foreach (['text', 'textarea', 'number', 'select', 'checkbox', 'date'] as $type) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __("forms.type.{$type}"));
        }
        foreach (['projects.invite', 'roles.owner', 'projects.no_layers', 'common.breadcrumb', 'maps.hide_layer', 'maps.show_layer'] as $key) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __($key));
        }
        $this->assertSame('منشورة على خادم الخرائط', __('layers.published_geoserver'));
        $this->assertSame('طبقة خادم الخرائط', __('layers.geoserver_layer'));
    }
}
