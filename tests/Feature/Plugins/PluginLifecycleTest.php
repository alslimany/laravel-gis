<?php

namespace Tests\Feature\Plugins;

use App\Models\InstalledPlugin;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\PluginEvent;
use App\Models\User;
use App\Plugins\PluginLifecycle;
use App\Plugins\PluginLifecycleException;
use App\Plugins\PluginManager;
use App\Plugins\PluginServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Plugins\Concerns\UsesHelloPlugin;
use Tests\Fixtures\Plugins\Hello\HelloServiceProvider;
use Tests\TestCase;

/**
 * #54 lifecycle (ADR-0001 rev 4 D4, D15, §6, §8, §12 Lifecycle).
 */
class PluginLifecycleTest extends TestCase
{
    use RefreshDatabase, UsesHelloPlugin;

    protected User $super;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelloPlugin();
        $this->org = Organization::factory()->create();
        $this->super = User::factory()->create(['organization_id' => $this->org->id]);
        $this->super->forceFill(['is_super_admin' => true])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownHelloPlugin();
        parent::tearDown();
    }

    protected function lifecycle(): PluginLifecycle
    {
        return app(PluginLifecycle::class);
    }

    protected function row(): ?InstalledPlugin
    {
        return InstalledPlugin::query()->where('plugin_key', 'hello')->first();
    }

    protected function events(string $action)
    {
        return PluginEvent::query()->where('plugin_key', 'hello')->where('action', $action)->get();
    }

    public function test_install_runs_plugin_migrations_in_own_batch_and_audits(): void
    {
        $coreBatch = (int) DB::table('migrations')->max('batch');

        $this->artisan('plugin:install', ['key' => 'hello', '--actor' => $this->super->email])->assertSuccessful();

        $row = $this->row();
        $this->assertSame(InstalledPlugin::STATUS_INSTALLED, $row->status);
        $this->assertSame('1.0.0', $row->version);
        $this->assertSame([$coreBatch + 1], $row->meta['batches']);
        $this->assertSame(['2026_01_01_000000_create_hello_items_table'], $row->meta['migrations']);
        $this->assertTrue(Schema::hasTable('hello_items'));
        $this->assertSame(
            ['2026_01_01_000000_create_hello_items_table'],
            DB::table('migrations')->where('batch', $coreBatch + 1)->pluck('migration')->all()
        );
        $this->assertSame(['install'], HelloServiceProvider::$calls);

        $event = $this->events(PluginEvent::INSTALL)->sole();
        $this->assertSame($this->super->id, (int) $event->actor_id);
        $this->assertNull($event->from_version);
        $this->assertSame('1.0.0', $event->to_version);
        $this->assertSame('ok', $event->outcome);
        $this->assertNotNull($event->created_at);
    }

    public function test_install_twice_fails(): void
    {
        $this->lifecycle()->install('hello');

        $this->artisan('plugin:install', ['key' => 'hello'])->assertFailed();
    }

    public function test_unknown_plugin_and_non_super_actor_fail(): void
    {
        $this->artisan('plugin:install', ['key' => 'nope'])->assertFailed();

        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->artisan('plugin:install', ['key' => 'hello', '--actor' => $member->email])->assertFailed();
        $this->assertNull($this->row());
    }

    public function test_failed_install_sets_failed_status_and_audits_failure(): void
    {
        HelloServiceProvider::$failOnInstall = true;

        try {
            $this->lifecycle()->install('hello', $this->super);
            $this->fail('install should throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('hello onInstall failed', $e->getMessage());
        }

        $this->assertSame(InstalledPlugin::STATUS_FAILED, $this->row()->status);
        $this->assertSame('failed', $this->events(PluginEvent::INSTALL)->sole()->outcome);
        $this->assertFalse(app(PluginManager::class)->isInstalled('hello'));
    }

    public function test_status_is_installing_during_migration(): void
    {
        $seen = null;
        DB::listen(function ($query) use (&$seen) {
            if ($seen === null && str_contains($query->sql, 'create table "hello_items"')) {
                $seen = InstalledPlugin::query()->where('plugin_key', 'hello')->value('status');
            }
        });

        $this->lifecycle()->install('hello');

        $this->assertSame(InstalledPlugin::STATUS_INSTALLING, $seen);
    }

    public function test_upgrade_runs_new_batch_bumps_version_and_audits(): void
    {
        $this->lifecycle()->install('hello');
        $first = $this->row()->meta['batches'][0];

        $this->artisan('plugin:upgrade', ['key' => 'hello'])->assertFailed(); // already current

        $this->addHelloV2Migration();
        $this->setHelloVersion('1.1.0');
        $this->lifecycle()->upgrade('hello', $this->super);

        $row = $this->row();
        $this->assertSame('1.1.0', $row->version);
        $this->assertSame(InstalledPlugin::STATUS_INSTALLED, $row->status);
        $this->assertSame([$first, $first + 1], $row->meta['batches']);
        $this->assertTrue(Schema::hasTable('hello_notes'));

        $event = $this->events(PluginEvent::UPGRADE)->sole();
        $this->assertSame(['1.0.0', '1.1.0'], [$event->from_version, $event->to_version]);
    }

    public function test_enable_disable_are_audited_with_org_and_actor_and_call_hooks(): void
    {
        $this->lifecycle()->install('hello');
        $manager = app(PluginManager::class);

        $manager->setEnabled('hello', $this->org, true, $this->super);
        $manager->setEnabled('hello', $this->org, false, $this->super);

        $enable = $this->events(PluginEvent::ENABLE)->sole();
        $this->assertSame($this->org->id, (int) $enable->organization_id);
        $this->assertSame($this->super->id, (int) $enable->actor_id);
        $this->assertTrue($enable->meta['super_admin']);
        $this->assertCount(1, $this->events(PluginEvent::DISABLE));
        $this->assertSame(['install', 'enable:'.$this->org->id, 'disable:'.$this->org->id], HelloServiceProvider::$calls);
    }

    public function test_uninstall_flips_state_keeps_data_and_marks_layers_source_missing(): void
    {
        $this->lifecycle()->install('hello');
        DB::table('hello_items')->insert(['organization_id' => $this->org->id, 'name' => 'kept']);
        $layer = Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => ['plugin' => 'hello']]);
        app(PluginManager::class)->setEnabled('hello', $this->org, true);

        $this->artisan('plugin:uninstall', ['key' => 'hello'])->assertSuccessful();

        $this->assertSame(InstalledPlugin::STATUS_UNINSTALLED, $this->row()->status);
        $this->assertNotNull($this->row()->uninstalled_at);
        $this->assertSame(1, DB::table('hello_items')->count());
        $this->assertTrue($layer->fresh()->metadata['source_missing']);
        $this->assertFalse(app(PluginManager::class)->isEnabledFor('hello', $this->org));
        $this->assertDatabaseHas('organization_plugins', ['plugin_key' => 'hello', 'enabled' => true]);
        $this->assertCount(1, $this->events(PluginEvent::UNINSTALL));

        // Reinstall: no new batch, data intact, source_missing cleared.
        $this->lifecycle()->install('hello');
        $this->assertCount(1, $this->row()->meta['batches']);
        $this->assertArrayNotHasKey('source_missing', $layer->fresh()->metadata);
    }

    public function test_purge_requires_uninstalled_and_typed_confirmation(): void
    {
        $this->lifecycle()->install('hello');
        $this->artisan('plugin:purge', ['key' => 'hello', '--confirm' => 'hello'])->assertFailed();

        $this->lifecycle()->uninstall('hello');
        $this->artisan('plugin:purge', ['key' => 'hello', '--confirm' => 'wrong'])->assertFailed();
        $this->assertTrue(Schema::hasTable('hello_items'));
    }

    public function test_purge_rolls_back_only_plugin_batches_even_with_later_core_batch(): void
    {
        $this->lifecycle()->install('hello');
        $this->addHelloV2Migration();
        $this->setHelloVersion('1.1.0');
        $this->lifecycle()->upgrade('hello');
        $pluginBatches = $this->row()->meta['batches'];

        // A core batch migrated after the plugin batches.
        Artisan::call('migrate', ['--path' => base_path('tests/Fixtures/CoreLater'), '--realpath' => true, '--force' => true]);
        $coreLaterBatch = (int) DB::table('migrations')->max('batch');
        $this->assertGreaterThan(max($pluginBatches), $coreLaterBatch);

        $layer = Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => ['plugin' => 'hello']]);
        $other = Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => ['plugin' => 'other']]);

        $this->lifecycle()->uninstall('hello');
        $this->artisan('plugin:purge', ['key' => 'hello', '--confirm' => 'hello', '--actor' => $this->super->email])
            ->expectsOutputToContain('GeoServer publications are NOT removed')
            ->assertSuccessful();

        $this->assertFalse(Schema::hasTable('hello_items'));
        $this->assertFalse(Schema::hasTable('hello_notes'));
        $this->assertTrue(Schema::hasTable('core_later'));
        $this->assertTrue(DB::table('migrations')->where('batch', $coreLaterBatch)->exists());
        $this->assertFalse(DB::table('migrations')->whereIn('batch', $pluginBatches)->exists());
        $this->assertModelMissing($layer);
        $this->assertModelExists($other);
        $this->assertNull($this->row());

        $event = $this->events(PluginEvent::PURGE)->sole();
        $this->assertSame($this->super->id, (int) $event->actor_id);
        $this->assertSame([$layer->id], $event->meta['deleted_layers']);
        $this->assertContains('purge', HelloServiceProvider::$calls);
    }

    public function test_rollback_refuses_batch_with_non_plugin_migrations(): void
    {
        // Simulate a batch shared with a core migration (should never happen).
        $this->lifecycle()->install('hello');
        $batch = $this->row()->meta['batches'][0];
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_core_thing', 'batch' => $batch]);
        $this->lifecycle()->uninstall('hello');

        $this->expectException(PluginLifecycleException::class);
        $this->lifecycle()->purge('hello');
    }

    public function test_purge_drops_only_manifest_tables(): void
    {
        $this->lifecycle()->install('hello');
        $this->lifecycle()->uninstall('hello');

        $summary = $this->lifecycle()->purge('hello');

        $this->assertSame([], $summary['dropped_tables']); // all removed by rollback
        $this->assertTrue(Schema::hasTable('layers'));
        $this->assertTrue(Schema::hasTable('organizations'));
    }

    public function test_register_override_must_call_parent(): void
    {
        $bad = new class($this->app) extends PluginServiceProvider
        {
            public function key(): string
            {
                return 'bad';
            }

            public function register(): void {}
        };
        $bad->register();

        $this->expectException(LogicException::class);
        $bad->boot();
    }

    public function test_register_override_calling_parent_boots(): void
    {
        $good = new class($this->app) extends PluginServiceProvider
        {
            public function key(): string
            {
                return 'good';
            }

            public function register(): void
            {
                parent::register();
            }
        };
        $good->register();
        $good->boot();

        $this->assertSame('good', $good->key());
    }

    public function test_manifest_without_data_prefix_is_rejected(): void
    {
        $this->setHelloVersion('1.0.0', ['tables' => ['hello_items', 'hello_notes']]);

        $this->assertNotSame([], PluginManager::validateManifest(app(PluginManager::class)->manifest('hello')));
        $this->expectException(PluginLifecycleException::class);
        $this->expectExceptionMessage('data.prefix is required');

        try {
            $this->lifecycle()->install('hello');
        } finally {
            $this->assertFalse(Schema::hasTable('hello_items'));
        }
    }

    public function test_manifest_with_table_outside_prefix_is_rejected(): void
    {
        $this->setHelloVersion('1.0.0', ['prefix' => 'hello_', 'tables' => ['hello_items', 'users']]);

        $errors = PluginManager::validateManifest(app(PluginManager::class)->manifest('hello'));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"users"', $errors[0]);
        $this->expectException(PluginLifecycleException::class);
        $this->expectExceptionMessage('does not start with prefix hello_');

        try {
            $this->lifecycle()->install('hello');
        } finally {
            $this->assertFalse(Schema::hasTable('hello_items'));
        }
    }

    public function test_failed_install_records_batch_and_plugin_rollback_recovers(): void
    {
        HelloServiceProvider::$failOnInstall = true;
        try {
            $this->lifecycle()->install('hello', $this->super);
        } catch (\RuntimeException) {
        }

        $row = $this->row();
        $this->assertSame(InstalledPlugin::STATUS_FAILED, $row->status);
        $this->assertCount(1, $row->meta['batches'], 'batch recorded in finally even though install failed');
        $this->assertTrue(Schema::hasTable('hello_items'));

        $this->artisan('plugin:rollback', ['key' => 'hello', '--actor' => $this->super->email])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('hello_items'));
        $this->assertFalse(DB::table('migrations')->whereIn('batch', $row->meta['batches'])->exists());
        $this->assertNull($this->row(), 'first install rolled back to not-installed');
        $event = $this->events(PluginEvent::ROLLBACK)->sole();
        $this->assertSame('ok', $event->outcome);
        $this->assertSame($this->super->id, $event->actor_id);
    }

    public function test_failed_upgrade_rolls_back_to_prior_version(): void
    {
        $this->lifecycle()->install('hello');
        $first = $this->row()->meta['batches'];
        $this->addHelloV2Migration();
        $this->setHelloVersion('2.0.0');
        // A migration that throws after the v2 migration ran in the same batch.
        file_put_contents($this->helloMigrations.'/2026_09_09_000000_boom.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void { throw new RuntimeException('boom'); } public function down(): void {} };\n");

        try {
            $this->lifecycle()->upgrade('hello');
            $this->fail('upgrade should throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(InstalledPlugin::STATUS_FAILED, $this->row()->status);
        @unlink($this->helloMigrations.'/2026_09_09_000000_boom.php');

        $summary = $this->lifecycle()->rollback('hello');

        $row = $this->row();
        $this->assertSame(InstalledPlugin::STATUS_INSTALLED, $row->status);
        $this->assertSame('1.0.0', $row->version);
        $this->assertSame($first, $row->meta['batches']);
        $this->assertArrayNotHasKey('pending', $row->meta);
        $this->assertFalse($summary['restored_backup']);
    }

    public function test_rollback_without_failed_operation_is_refused(): void
    {
        $this->lifecycle()->install('hello');

        $this->expectException(PluginLifecycleException::class);
        $this->lifecycle()->rollback('hello');
    }

    public function test_lifecycle_lock_blocks_concurrent_operations(): void
    {
        config(['plugins.lock_wait' => 0]);
        $lock = Cache::store(config('plugins.lock_store'))->lock('plugin-lifecycle', 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(PluginLifecycleException::class);
            $this->expectExceptionMessage('Another plugin lifecycle operation is running');
            $this->lifecycle()->install('hello');
        } finally {
            $lock->release();
        }
    }

    public function test_purge_checks_all_batches_before_deleting_layers(): void
    {
        $this->lifecycle()->install('hello');
        $batch = $this->row()->meta['batches'][0];
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_core_thing', 'batch' => $batch]);
        $layer = Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => ['plugin' => 'hello']]);
        $this->lifecycle()->uninstall('hello');

        try {
            $this->lifecycle()->purge('hello');
            $this->fail('purge should refuse');
        } catch (PluginLifecycleException) {
        }

        $this->assertModelExists($layer);
        $this->assertTrue(Schema::hasTable('hello_items'));
        $this->assertSame(0, $this->events(PluginEvent::PURGE)->count(), 'refused before any work');
    }

    public function test_cli_runs_without_actor_are_audited_as_cli(): void
    {
        $this->artisan('plugin:install', ['key' => 'hello'])->assertSuccessful();
        $this->artisan('plugin:uninstall', ['key' => 'hello'])->assertSuccessful();
        $this->artisan('plugin:purge', ['key' => 'hello', '--confirm' => 'hello'])
            ->expectsOutputToContain('GeoServer publications are NOT removed')
            ->assertSuccessful();

        foreach ([PluginEvent::INSTALL, PluginEvent::UNINSTALL, PluginEvent::PURGE] as $action) {
            $event = $this->events($action)->sole();
            $this->assertNull($event->actor_id);
            $this->assertSame('cli', $event->meta['actor']);
        }
        $this->assertSame('driver', $this->events(PluginEvent::INSTALL)->sole()->meta['backup_skipped']);
    }

    public function test_plugin_layers_filters_by_key_in_query(): void
    {
        $mine = Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => ['plugin' => 'hello']]);
        Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => ['plugin' => 'hello-2']]);
        Layer::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->super->id, 'metadata' => null]);

        $this->assertSame([$mine->id], $this->lifecycle()->pluginLayers('hello')->pluck('id')->all());
    }
}
