<?php

namespace Lumina\Disaster\Tests;

use App\Events\WarningIssued;
use App\Models\InstalledPlugin;
use App\Models\Organization;
use App\Models\OrganizationPlugin;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Lumina\Disaster\Demo\NcmcdDemoSeeder;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\AvlPing;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\DamageFormSchema;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\IncidentRole;
use Lumina\Disaster\Models\InventoryLedger;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\LifelineStatus;
use Lumina\Disaster\Models\MessageTemplate;
use Lumina\Disaster\Models\ResourceRequest;
use Lumina\Disaster\Models\ResourceType;
use Lumina\Disaster\Models\ShelterCheckin;
use Lumina\Disaster\Models\SitRep;
use Lumina\Disaster\Models\Task;
use Lumina\Disaster\Models\Volunteer;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Providers\DisasterServiceProvider;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Models\AlertRule;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Lumina\EarlyWarning\Models\RiskAssessment;
use Lumina\EarlyWarning\Providers\EarlyWarningServiceProvider;
use Tests\TestCase;

class NcmcdDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([DisasterServiceProvider::class, EarlyWarningServiceProvider::class] as $provider) {
            $this->app->register($provider);
            $this->artisan('migrate', ['--path' => $provider::migrationsPath(), '--realpath' => true])->assertExitCode(0);
        }
        foreach (['disaster' => '0.5.0', 'early-warning' => '0.4.0'] as $key => $version) {
            (new InstalledPlugin)->forceFill([
                'plugin_key' => $key, 'version' => $version, 'status' => InstalledPlugin::STATUS_INSTALLED,
                'installed_at' => now(), 'meta' => [],
            ])->save();
        }
        app(PluginManager::class)->forgetInstalled();

        foreach ([['TRP-MAJENIN', 'وادي المجينين'], ['TRP-RABIE', 'وادي الربيع'], ['TRP-HIRA', 'وادي الهيرة']] as [$code, $ar]) {
            Basin::query()->create(['basin_code' => $code, 'name' => $code, 'name_ar' => $ar, 'region' => 'tripoli']);
        }
        // A pre-existing org with legacy DEMO- content must stay untouched.
        Organization::factory()->create(['name' => 'Other org']);

        // Never write the real storage/app/private credentials file from tests.
        $this->app->useStoragePath(sys_get_temp_dir().'/ncmcd-demo-test-storage-'.getmypid().'-'.uniqid());

        Notification::fake();
        Mail::fake();
        Queue::fake();
        Event::fake([WarningIssued::class]);
    }

    private function seedDemo(array $args = []): void
    {
        $this->artisan('disaster:seed-ncmcd-demo', $args)->assertExitCode(0);
    }

    /** @return array<string, int> */
    private function counts(int $orgId): array
    {
        return [
            'orgs' => Organization::query()->where('name', NcmcdDemoSeeder::ORG_NAME)->count(),
            'users' => User::query()->where('email', 'like', '%@ncmcd-demo.test')->count(),
            'projects' => Project::query()->where('organization_id', $orgId)->count(),
            'incidents' => Incident::query()->where('organization_id', $orgId)->count(),
            'closures' => Closure::query()->where('organization_id', $orgId)->count(),
            'sites' => CriticalSite::query()->where('organization_id', $orgId)->count(),
            'activations' => Activation::query()->where('organization_id', $orgId)->count(),
            'risk' => RiskAssessment::query()->where('organization_id', $orgId)->count(),
            'forecasts' => Forecast::query()->where('source', NcmcdDemoSeeder::FORECAST_SOURCE)->count(),
            'alert_rules' => AlertRule::query()->where('organization_id', $orgId)->count(),
            'alert_logs' => AlertLog::query()->where('organization_id', $orgId)->count(),
            'pings' => AvlPing::query()->where('organization_id', $orgId)->count(),
            'checkins' => ShelterCheckin::query()->where('organization_id', $orgId)->count(),
            'lifeline_statuses' => LifelineStatus::query()->where('organization_id', $orgId)->count(),
            'templates' => MessageTemplate::query()->where('organization_id', $orgId)->count(),
            'ics_roles' => IncidentRole::query()->where('organization_id', $orgId)->count(),
            'role_user' => \DB::table('role_user')->where('organization_id', $orgId)->count(),
            'basins' => Basin::query()->count(),
            'evac_orders' => EvacuationOrder::query()->where('organization_id', $orgId)->count(),
            'volunteers' => Volunteer::query()->where('organization_id', $orgId)->count(),
            'resource_types' => ResourceType::query()->where('organization_id', $orgId)->count(),
            'requests' => ResourceRequest::query()->where('organization_id', $orgId)->count(),
            'ledger' => InventoryLedger::query()->where('organization_id', $orgId)->count(),
        ];
    }

    private function org(): Organization
    {
        return Organization::query()->where('name', NcmcdDemoSeeder::ORG_NAME)->firstOrFail();
    }

    public function test_double_run_is_idempotent(): void
    {
        $this->seedDemo();
        $first = $this->counts($this->org()->id);
        $hashes = User::query()->where('email', 'like', '%@ncmcd-demo.test')->pluck('password', 'email');

        $this->seedDemo();
        $this->assertSame($first, $this->counts($this->org()->id));
        $this->assertEquals($hashes, User::query()->where('email', 'like', '%@ncmcd-demo.test')->pluck('password', 'email'));

        $this->assertSame(1, $first['orgs']);
        $this->assertSame(1, Activation::query()->where('organization_id', $this->org()->id)->count());
        $this->assertSame(4, $first['users']);
        $this->assertSame(5, $first['incidents']);
        $this->assertSame(3, $first['closures']);
        $this->assertSame(3, $first['basins'], 'basins are reused, never created');
        $this->assertSame(4, $first['pings']);
        $this->assertSame(7, $first['templates']);
        $this->assertSame(3, $first['ics_roles']);
        $this->assertSame(1, $first['evac_orders']);
        $this->assertSame(5, $first['alert_rules'], '4 defaults + 1 scenario rule');
    }

    public function test_users_are_arabic_and_roles_follow_pm_decision(): void
    {
        $this->seedDemo();
        $org = $this->org();
        $expected = [
            'admin@ncmcd-demo.test' => 'admin',
            'duty@ncmcd-demo.test' => 'admin',
            'commander@ncmcd-demo.test' => 'editor',
            'field@ncmcd-demo.test' => 'viewer',
        ];
        foreach ($expected as $email => $role) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertSame('ar', $user->preferred_locale);
            $this->assertSame($org->id, $user->organization_id);
            $this->assertTrue($user->hasRoleInOrg($role, $org->id), "{$email} should be {$role}");
            $this->assertSame(1, \DB::table('role_user')->where('user_id', $user->id)->where('organization_id', $org->id)->count());
        }
        $this->assertSame(User::query()->where('email', 'admin@ncmcd-demo.test')->value('id'), $org->user_id);

        $commander = User::query()->where('email', 'commander@ncmcd-demo.test')->first();
        $this->assertTrue(IncidentRole::query()->where('organization_id', $org->id)
            ->where('role', 'incident_commander')->where('user_id', $commander->id)->exists());
    }

    public function test_content_is_arabic_exercise_and_prefixed(): void
    {
        $this->seedDemo();
        $org = $this->org();

        $activation = Activation::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertTrue($activation->is_exercise);
        $this->assertSame('NCMCD-W26-ALERT-001', $activation->source_alert_id);
        $this->assertStringStartsWith('تمرين افتراضي', $activation->advisory_text);

        foreach (Incident::query()->where('organization_id', $org->id)->get() as $incident) {
            $this->assertStringStartsWith('NCMCD-W26-', $incident->external_id);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $incident->title);
        }
        foreach (MessageTemplate::query()->where('organization_id', $org->id)->get() as $t) {
            $this->assertSame('ar', $t->language);
            $this->assertStringStartsWith('تمرين', $t->body);
        }
        foreach (CriticalSite::query()->where('organization_id', $org->id)->get() as $site) {
            $this->assertStringContainsString('افتراضي', $site->name);
        }

        $this->assertSame(['major', 'major', 'moderate'],
            Closure::query()->where('organization_id', $org->id)->orderBy('external_id')->pluck('severity')->all());

        $this->assertFalse(AlertRule::query()->where('organization_id', $org->id)->firstOrFail()->enabled);
        $this->assertSame(0, Incident::query()->where('organization_id', '!=', $org->id)->count());
        $this->assertSame(0, Incident::query()->where('external_id', 'like', 'DEMO-%')->count());

        $texts = Incident::query()->pluck('title')->merge(MessageTemplate::query()->pluck('body'))->implode(' ')
            .' '.$org->name.' '.$org->description;
        $this->assertStringNotContainsString('المنظمة', $texts);
    }

    public function test_stored_warning_shows_in_ew_feed(): void
    {
        $this->seedDemo();
        $director = User::query()->where('email', 'admin@ncmcd-demo.test')->firstOrFail();

        $basins = collect($this->actingAs($director)->getJson('/x/early-warning/warnings?hours=6')->assertOk()->json('basins'));
        $majenin = $basins->firstWhere('basin_code', 'TRP-MAJENIN');
        $this->assertSame('red', $majenin['risk_level']);
        $this->assertNotNull($majenin['precip_mm']);
    }

    public function test_sends_nothing(): void
    {
        $this->seedDemo();
        $this->seedDemo(['--reset-passwords' => true]);

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Event::assertNotDispatched(WarningIssued::class);
    }

    public function test_same_name_org_without_marker_is_refused_and_untouched(): void
    {
        $owner = User::factory()->create();
        $impostor = Organization::factory()->create([
            'name' => NcmcdDemoSeeder::ORG_NAME, 'description' => 'real org', 'user_id' => $owner->id,
        ]);
        $before = $impostor->fresh()->toArray();

        $this->artisan('disaster:seed-ncmcd-demo')
            ->expectsOutputToContain('already exists without the ncmcd-w26 demo marker')
            ->assertExitCode(1);

        $this->assertSame($before, $impostor->fresh()->toArray());
        $this->assertSame(0, User::query()->where('email', 'like', '%@ncmcd-demo.test')->count());
        $this->assertSame(0, OrganizationPlugin::query()->where('organization_id', $impostor->id)->count());
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_org_is_found_by_marker_not_name(): void
    {
        $this->seedDemo();
        $org = $this->org();
        foreach (['disaster', 'early-warning'] as $key) {
            $settings = OrganizationPlugin::query()->where('organization_id', $org->id)->where('plugin_key', $key)->firstOrFail()->settings;
            $this->assertSame('ncmcd-w26', $settings['demo_key']);
            $this->assertTrue($settings['exercise']);
        }

        // Renaming the org must not make a second run create a new one.
        $org->forceFill(['name' => 'renamed demo org'])->save();
        $this->seedDemo();
        $this->assertSame(1, Organization::query()->whereKey($org->id)->count());
        $this->assertSame(0, Organization::query()->where('name', NcmcdDemoSeeder::ORG_NAME)->count());
        $this->assertSame(5, Incident::query()->where('organization_id', $org->id)->count());
    }

    public function test_passwords_go_only_to_the_0600_credentials_file(): void
    {
        $this->assertSame(0, Artisan::call('disaster:seed-ncmcd-demo'));
        $output = Artisan::output();

        $path = storage_path('app/private/ncmcd-demo-credentials.txt');
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertStringContainsString($path, $output);

        $lines = array_filter(file($path, FILE_IGNORE_NEW_LINES), fn ($l) => $l !== '' && $l[0] !== '#');
        $this->assertCount(4, $lines);
        foreach ($lines as $line) {
            [$email, $password] = explode("\t", $line);
            $this->assertStringEndsWith('@ncmcd-demo.test', $email);
            $this->assertGreaterThanOrEqual(20, strlen($password));
            $this->assertStringNotContainsString($password, $output);
        }
    }

    public function test_production_needs_force_and_i_understand_demo(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('disaster:seed-ncmcd-demo')->assertExitCode(1);
        $this->artisan('disaster:seed-ncmcd-demo', ['--force' => true])->assertExitCode(1);
        $this->assertSame(0, User::query()->where('email', 'like', '%@ncmcd-demo.test')->count());

        $this->artisan('disaster:seed-ncmcd-demo', ['--force' => true, '--i-understand-demo' => true])->assertExitCode(0);
        $this->assertSame(4, User::query()->where('email', 'like', '%@ncmcd-demo.test')->count());
    }

    public function test_timeline_is_anchored_on_seed_time(): void
    {
        $this->seedDemo();
        $org = $this->org();
        $offsetMinutes = 4 * 60; // SEED_TIME_IS = 04:00
        $this->assertSame('04:00', NcmcdDemoSeeder::SEED_TIME_IS);

        $inc01 = Incident::query()->where('external_id', 'NCMCD-W26-INC-01')->firstOrFail();
        // INC-01 opened at 03:10 → 50 minutes before seed time.
        $this->assertEqualsWithDelta(now()->subMinutes(50)->timestamp, $inc01->opened_at->timestamp, 61);
        $this->assertSame('red', RiskAssessment::query()->where('organization_id', $org->id)
            ->where('basin_code', 'TRP-MAJENIN')->orderBy('forecast_at')->value('risk_level'));
        $this->assertGreaterThan(0, $offsetMinutes);
    }

    public function test_user_facing_arabic_fields_have_no_code_prefix(): void
    {
        $this->seedDemo();
        $orgId = $this->org()->id;
        $fields = [
            [Volunteer::class, 'name'], [ResourceType::class, 'name'], [Warehouse::class, 'name'],
            [LifelineComponent::class, 'name'], [Task::class, 'title'], [SitRep::class, 'summary'],
            [EvacCorridor::class, 'name'], [ResourceRequest::class, 'notes'], [AlertRule::class, 'name'],
            [MessageTemplate::class, 'headline'], [MessageTemplate::class, 'body'], [Incident::class, 'title'],
            [CriticalSite::class, 'name'], [InventoryLedger::class, 'note'],
        ];
        foreach ($fields as [$model, $column]) {
            if (! \Schema::hasColumn((new $model)->getTable(), $column)) {
                continue;
            }
            $values = $model::query()->where('organization_id', $orgId)->pluck($column)->filter();
            $this->assertNotEmpty($values, "{$model}.{$column} seeded");
            foreach ($values as $value) {
                $this->assertStringNotContainsString('NCMCD', (string) $value, "{$model}.{$column}");
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $value, "{$model}.{$column}");
            }
        }
        // Codes stay in code/key columns and props.
        $this->assertSame('NCMCD-W26-CORRIDOR-01', EvacCorridor::query()->where('organization_id', $orgId)->first()->props['code']);
        // The damage form is the one spec exception, with Arabic option labels and habitability.
        $form = DamageFormSchema::query()->where('organization_id', $orgId)->firstOrFail();
        $this->assertSame('NCMCD-W26-حصر سكني سريع', $form->name);
        $fieldsByKey = collect($form->schema['fields'])->keyBy('key');
        $this->assertSame(['habitable', 'limited', 'uninhabitable'], array_column($fieldsByKey['habitability']['options'], 'value'));
        $this->assertSame('سكني', $fieldsByKey['structure_type']['options'][0]['label']);
        // Evacuation order is keyed on the NCMCD-W26-ALERT-001 activation.
        $order = EvacuationOrder::query()->where('organization_id', $orgId)->firstOrFail();
        $this->assertSame('NCMCD-W26-ALERT-001', Activation::query()->find($order->activation_id)->source_alert_id);
    }

    public function test_default_alert_rules_are_arabic_and_disabled_in_demo_org_only(): void
    {
        $other = Organization::query()->where('name', 'Other org')->firstOrFail();
        AlertRule::query()->create([
            'organization_id' => $other->id, 'name' => 'Heavy 6h rain (yellow)', 'metric' => 'precip_6h_mm',
            'threshold' => 20, 'enabled' => true,
        ]);

        $this->seedDemo();
        $rules = AlertRule::query()->where('organization_id', $this->org()->id)->get();
        foreach (NcmcdDemoSeeder::DEFAULT_RULE_NAMES_AR as $en => $ar) {
            $this->assertFalse($rules->contains('name', $en));
            $this->assertTrue($rules->firstWhere('name', $ar)?->enabled === false, $ar);
        }
        $this->assertTrue($rules->every(fn ($r) => $r->enabled === false));

        $untouched = AlertRule::query()->where('organization_id', $other->id)->firstOrFail();
        $this->assertSame('Heavy 6h rain (yellow)', $untouched->name);
        $this->assertTrue($untouched->enabled);
    }

    public function test_basin_arabic_names_set_without_touching_english_name(): void
    {
        $this->seedDemo();
        $this->seedDemo();
        foreach (NcmcdDemoSeeder::BASIN_NAMES_AR as $code => $ar) {
            $basin = Basin::query()->where('basin_code', $code)->firstOrFail();
            $this->assertSame($ar, $basin->name_ar);
            $this->assertSame($code, $basin->name, 'shared English name unchanged');
        }
        $this->assertSame(3, Basin::query()->count());
    }

    public function test_failure_mid_seed_rolls_everything_back(): void
    {
        // Force a failure after users/org are written: drop a table the seeder needs late.
        \Schema::drop('dm_person_reports');
        try {
            $this->artisan('disaster:seed-ncmcd-demo');
        } catch (\Throwable) {
            // either the command catches it or it bubbles; both are fine
        }
        $this->assertSame(0, User::query()->where('email', 'like', '%@ncmcd-demo.test')->count());
        $this->assertSame(0, Organization::query()->where('name', NcmcdDemoSeeder::ORG_NAME)->count());
        $this->assertSame(0, Incident::query()->count());
        $this->assertFileDoesNotExist(storage_path('app/private/ncmcd-demo-credentials.txt'));
    }

    public function test_demo_email_owned_by_another_org_is_refused_before_any_write(): void
    {
        $other = Organization::query()->where('name', 'Other org')->firstOrFail();
        $role = Role::query()->firstOrCreate(['name' => 'editor'], ['description' => 'Editor']);
        $victim = User::factory()->create(['email' => 'duty@ncmcd-demo.test', 'organization_id' => $other->id, 'name' => 'Real person']);
        $victim->roles()->attach($role->id, ['organization_id' => $other->id]);

        $userBefore = $victim->fresh()->makeVisible(['password'])->toArray();
        $orgBefore = $other->fresh()->toArray();
        $rolesBefore = \DB::table('role_user')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $orgCount = Organization::query()->count();
        $userCount = User::query()->count();

        $this->artisan('disaster:seed-ncmcd-demo')
            ->expectsOutputToContain('duty@ncmcd-demo.test')
            ->assertExitCode(1);

        $this->assertSame($userBefore, $victim->fresh()->makeVisible(['password'])->toArray());
        $this->assertSame($orgBefore, $other->fresh()->toArray());
        $this->assertSame($rolesBefore, \DB::table('role_user')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($orgCount, Organization::query()->count());
        $this->assertSame($userCount, User::query()->count());
        $this->assertSame(0, OrganizationPlugin::query()->count());
        $this->assertSame(0, Incident::query()->count());
        $this->assertFileDoesNotExist(storage_path('app/private/ncmcd-demo-credentials.txt'));
    }

    public function test_demo_email_moved_out_of_marked_org_is_refused_on_rerun(): void
    {
        $this->seedDemo();
        $other = Organization::query()->where('name', 'Other org')->firstOrFail();
        $field = User::query()->where('email', 'field@ncmcd-demo.test')->firstOrFail();
        $field->forceFill(['organization_id' => $other->id])->save();

        $this->artisan('disaster:seed-ncmcd-demo')->expectsOutputToContain('field@ncmcd-demo.test')->assertExitCode(1);
        $this->assertSame($other->id, $field->fresh()->organization_id);
    }

    public function test_reset_passwords_rotates_hashes(): void
    {
        $this->seedDemo();
        $before = User::query()->where('email', 'field@ncmcd-demo.test')->value('password');
        $this->seedDemo(['--reset-passwords' => true]);
        $this->assertNotSame($before, User::query()->where('email', 'field@ncmcd-demo.test')->value('password'));
    }
}
