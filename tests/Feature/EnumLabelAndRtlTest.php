<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\DashboardWidgetDocument;
use App\Support\EnumLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** AR-6 / RTL: shared enum labels, Arabic layer names, and the server-rendered <html lang dir>. */
class EnumLabelAndRtlTest extends TestCase
{
    use RefreshDatabase;

    public function test_enum_label_translates_raw_values_in_arabic_and_english(): void
    {
        app()->setLocale('ar');
        $this->assertSame('معرّض للخطر', EnumLabel::for('at_risk'));
        $this->assertSame('أحمر', EnumLabel::for('Red'));
        $this->assertSame('شديد', EnumLabel::for('Severe'));
        $this->assertSame('فوري', EnumLabel::for('Immediate'));
        $this->assertSame('جسر', EnumLabel::for('bridge'));
        $this->assertSame('السلامة والأمن', EnumLabel::for('safety_and_security'));

        app()->setLocale('en');
        $this->assertSame('At risk', EnumLabel::for('at_risk'));
        $this->assertSame('Food, Hydration, Shelter', EnumLabel::for('food_hydration_shelter'));
    }

    public function test_unknown_value_is_humanised_and_prefix_wins(): void
    {
        app()->setLocale('ar');
        $this->assertSame('Some new value', EnumLabel::for('some_new_value'));
        $this->assertSame('أولوية 1 (الأعلى)', EnumLabel::for('1', null, 'disaster.priority'));
        $this->assertSame('', EnumLabel::for(null));
    }

    public function test_every_enum_key_exists_in_both_catalogs(): void
    {
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true);

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($ar))), 'keys missing in ar.json');
        $this->assertSame([], array_values(array_diff(array_keys($ar), array_keys($en))), 'keys missing in en.json');
        foreach ($ar as $key => $value) {
            $this->assertStringNotContainsString('المنظمة', (string) $value, "{$key} must say الجهة, not المنظمة");
        }
    }

    /**
     * Terminology (Abdullah, 1 Nov 2026): "incident" is «بلاغ / البلاغات», never «حادث / الحوادث».
     * Incident Commander is «المسؤول عن البلاغ». No allowlist: «قائد الحادث» must never appear.
     */
    public const INCIDENT_TERM_ALLOWLIST = [];

    public function test_incident_is_called_balagh_everywhere(): void
    {
        $sources = [lang_path('ar.json'), base_path('packages/disaster/demo/ncmcd-scenario.md'),
            base_path('packages/disaster/DEMO_CONTENT.md'), base_path('packages/disaster/src/Demo/NcmcdDemoSeeder.php'),
            ...self::manifestSources()];
        $sources = [...$sources, ...glob(base_path('packages/disaster/resources/views/{,pdf/}*.blade.php'), GLOB_BRACE)];

        foreach ($sources as $path) {
            $raw = (string) file_get_contents($path);
            $this->assertStringNotContainsString('قائد الحادث', $raw, basename($path).': Incident Commander is «المسؤول عن البلاغ».');
            $text = self::INCIDENT_TERM_ALLOWLIST === [] ? $raw : str_replace(self::INCIDENT_TERM_ALLOWLIST, '', $raw);
            $this->assertDoesNotMatchRegularExpression('/حادث|حوادث/u', $text, basename($path).' uses «حادث/الحوادث» for incident; use «بلاغ/البلاغات».');
            $this->assertDoesNotMatchRegularExpression('/المنظمة|منظمتك/u', basename($path) === 'ar.json' ? $text : '', 'ar.json must say الجهة');
        }
    }

    /**
     * Terminology (Abdullah, 1 Nov 2026): the Mitigation phase/module is «الوقاية» / "Prevention".
     * No legitimate other use of «التخفيف» exists today, so the allowlist is empty.
     */
    public const MITIGATION_ALLOWLIST = [];

    public function test_mitigation_is_called_prevention(): void
    {
        $arSources = [lang_path('ar.json'), base_path('packages/disaster/demo/ncmcd-scenario.md'),
            base_path('packages/disaster/DEMO_CONTENT.md'), base_path('packages/disaster/src/Demo/NcmcdDemoSeeder.php'),
            ...self::manifestSources(), ...glob(base_path('packages/disaster/resources/views/{,pdf/}*.blade.php'), GLOB_BRACE)];
        foreach ($arSources as $path) {
            $text = str_replace(self::MITIGATION_ALLOWLIST, '', (string) file_get_contents($path));
            $this->assertDoesNotMatchRegularExpression('/التخفيف|تخفيف/u', $text, basename($path).': use «الوقاية», not «التخفيف».');
        }

        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        foreach ($en as $key => $value) {
            $this->assertDoesNotMatchRegularExpression('/\\bMitigation\\b/i', (string) $value, "en.json {$key}: use Prevention.");
        }
        $manifest = (string) file_get_contents(base_path('packages/disaster/composer.json'));
        $this->assertDoesNotMatchRegularExpression('/"(label|description)_en":\\s*"[^"]*Mitigation/i', $manifest);
    }

    /** Plugin manifests, composer.lock and (when present) the installed.json snapshot the registry reads. */
    private static function manifestSources(): array
    {
        return array_values(array_filter([
            ...glob(base_path('packages/*/composer.json')),
            base_path('composer.lock'),
            base_path('vendor/composer/installed.json'),
        ], 'is_file'));
    }

    public function test_live_plugin_registry_has_current_terminology(): void
    {
        $registry = json_encode(app(PluginManager::class)->registry(), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/حادث|حوادث|التخفيف/u', (string) $registry);
        $this->assertStringContainsString('الوقاية', (string) $registry);
        $this->assertStringContainsString('Disaster & Emergency Management', (string) $registry);
    }

    public function test_registry_cache_key_changes_when_a_manifest_changes(): void
    {
        $dir = sys_get_temp_dir().'/plugreg-'.uniqid();
        mkdir($dir.'/vendor/composer', 0777, true);
        mkdir($dir.'/packages/demo', 0777, true);
        file_put_contents($dir.'/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents($dir.'/composer.lock', '{}');
        file_put_contents($dir.'/packages/demo/composer.json', '{"a":1}');
        $manager = new PluginManager($dir.'/vendor/composer/installed.json');

        $before = $manager->registryCacheKey();
        file_put_contents($dir.'/packages/demo/composer.json', '{"a":12}');
        clearstatcache();
        // Memoised: same instance keeps its key until flushed (one stat pass per request).
        $this->assertSame($before, $manager->registryCacheKey());
        $manager->flushRegistryKey();
        $this->assertNotSame($before, $after = $manager->registryCacheKey());

        file_put_contents($dir.'/composer.lock', '{"x":1}');
        clearstatcache();
        $manager->flushRegistryKey();
        $this->assertNotSame($after, $after2 = $manager->registryCacheKey());

        file_put_contents($dir.'/vendor/composer/installed.json', '{"packages":[ ]}');
        clearstatcache();
        $this->assertNotSame($after2, (new PluginManager($dir.'/vendor/composer/installed.json'))->registryCacheKey());
    }

    public function test_plugin_manager_is_scoped_per_request(): void
    {
        $first = app(PluginManager::class);
        $this->assertSame($first, app(PluginManager::class));
        app()->forgetScopedInstances();
        $this->assertNotSame($first, app(PluginManager::class));
    }

    public function test_dashboard_builder_catalog_and_messages_are_arabic(): void
    {
        app()->setLocale('ar');
        $catalog = app(DashboardWidgetDocument::class)->catalog();
        foreach ($catalog as $item) {
            foreach (['label', 'group', 'description'] as $field) {
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3}/', $item[$field], "catalog {$item['type']}.{$field}");
            }
        }
        foreach (['Builder', 'Inspector', 'Library', 'WidgetBoard', 'widgets', 'DashboardBoard'] as $file) {
            $src = (string) file_get_contents(resource_path("js/dashboards/{$file}.jsx"));
            preg_match_all("/__\\('([a-z_.]+)'/", $src, $m);
            foreach (array_unique($m[1]) as $key) {
                $this->assertNotSame($key, __($key), "{$file}.jsx uses {$key} without an Arabic entry");
            }
            // No bare English JSX text or label/hint/placeholder literals left.
            $this->assertDoesNotMatchRegularExpression('/\b(label|hint|placeholder|aria-label|title)="[A-Z][a-z]/', $src, "{$file}.jsx");
        }
    }

    public function test_evacuation_workflow_and_geometry_labels_are_arabic(): void
    {
        app()->setLocale('ar');
        foreach (['created', 'submit_for_approval', 'approve', 'issue', 'start_execution', 'complete', 'cancel', 'return_to_draft', 'update_execution'] as $action) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __("disaster.evac_action.{$action}"), $action);
        }
        foreach (['pending_approval', 'approved', 'draft', 'issued', 'in_progress', 'completed', 'cancelled'] as $status) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __("disaster.evac_transition.{$status}"), $status);
        }
        $this->assertSame('منطقة محددة على الخريطة', __('disaster.geojson_polygon'));
        $this->assertSame('مضلع', EnumLabel::for('MultiPolygon'));
        $this->assertSame('خط', EnumLabel::for('LineString'));
        $this->assertSame('نقطة', EnumLabel::for('Point'));
        $this->assertSame('الرسائل النصية', EnumLabel::for('sms'));
        $this->assertStringNotContainsString('EW_SMS', __('ew.sms_log_only'));
    }

    public function test_blade_root_renders_lang_and_dir_from_resolved_locale(): void
    {
        config(['app.locale' => 'ar']);
        $html = (string) $this->withHeader('Accept-Language', 'en')->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*lang="ar"[^>]*dir="rtl"/', $html);

        $this->flushSession();
        $html = (string) $this->withSession(['locale' => 'en'])->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*lang="en"[^>]*dir="ltr"/', $html);
    }

    public function test_guest_pages_get_locale_switch_props(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('localeUpdateUrl', route('locale.update'))
            ->has('localeOptions', 2)
            ->has('locale'));
    }

    public function test_plugin_layers_show_arabic_name_only_in_arabic(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $layer = Layer::query()->create([
            'organization_id' => $user->organization_id, 'user_id' => $user->id, 'name' => 'Road closures',
            'table_name' => 'dm_org_closures_x', 'metadata' => ['plugin' => 'disaster', 'name_ar' => 'إغلاقات الطرق'],
        ]);
        $own = Layer::query()->create([
            'organization_id' => $user->organization_id, 'user_id' => $user->id, 'name' => 'My layer',
            'table_name' => 'my_layer', 'metadata' => ['name_ar' => 'لا تُستخدم'],
        ]);

        app()->setLocale('ar');
        $this->assertSame('إغلاقات الطرق', $layer->fresh()->name);
        $this->assertSame('My layer', $own->fresh()->name);
        app()->setLocale('en');
        $this->assertSame('Road closures', $layer->fresh()->name);
    }
}
