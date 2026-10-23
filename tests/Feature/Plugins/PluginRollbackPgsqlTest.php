<?php

namespace Tests\Feature\Plugins;

use App\Models\InstalledPlugin;
use App\Plugins\PluginBackup;
use App\Plugins\PluginLifecycle;
use App\Plugins\PluginLifecycleException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Plugins\Concerns\UsesHelloPlugin;
use Tests\TestCase;
use Throwable;

/**
 * QA repro (d84f836): failed upgrade leaves a pending backup, a core table
 * FKs to a plugin table, rollback's down() would succeed. The core-dependency
 * check must run before any batch is rolled back, so nothing changes.
 *
 * Runs the real lifecycle on a scratch PostgreSQL DB (PLUGIN_PGSQL_TEST_DB,
 * optional PLUGIN_PGSQL_TEST_HOST/PORT/USER/PASSWORD); skipped otherwise.
 */
class PluginRollbackPgsqlTest extends TestCase
{
    use UsesHelloPlugin;

    protected function setUp(): void
    {
        parent::setUp();

        $database = env('PLUGIN_PGSQL_TEST_DB');
        if (! $database) {
            $this->markTestSkipped('PLUGIN_PGSQL_TEST_DB not set.');
        }
        config(['database.connections.pgsql_rollback_test' => array_merge(config('database.connections.pgsql'), [
            'url' => null, 'database' => $database,
            'host' => env('PLUGIN_PGSQL_TEST_HOST', config('database.connections.pgsql.host')),
            'port' => env('PLUGIN_PGSQL_TEST_PORT', config('database.connections.pgsql.port')),
            'username' => env('PLUGIN_PGSQL_TEST_USER', config('database.connections.pgsql.username')),
            'password' => env('PLUGIN_PGSQL_TEST_PASSWORD', config('database.connections.pgsql.password')),
        ])]);
        try {
            DB::connection('pgsql_rollback_test')->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available: '.$e->getMessage());
        }
        foreach (['pg_dump', 'pg_restore', 'psql'] as $tool) {
            try {
                $ok = Process::run([PluginBackup::bin($tool), '--version'])->successful();
            } catch (Throwable) {
                $ok = false;
            }
            if (! $ok) {
                $this->markTestSkipped("{$tool} not available.");
            }
        }

        config(['database.default' => 'pgsql_rollback_test', 'plugins.lock_store' => 'array']);
        DB::setDefaultConnection('pgsql_rollback_test');
        $this->dropScratch();
        Schema::create('migrations', function (Blueprint $t) {
            $t->increments('id');
            $t->string('migration');
            $t->integer('batch');
        });
        // Pretend core is migrated (batch 1) so plugin install/upgrade proceed.
        DB::table('migrations')->insert(collect(array_keys(app('migrator')->getMigrationFiles([database_path('migrations')])))
            ->map(fn ($m) => ['migration' => $m, 'batch' => 1])->all());
        Schema::create('organizations', fn (Blueprint $t) => $t->id());
        Schema::create('layers', function (Blueprint $t) {
            $t->id();
            $t->jsonb('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('installed_plugins', function (Blueprint $t) {
            $t->id();
            $t->string('plugin_key', 64)->unique();
            $t->string('version', 32);
            $t->string('status', 16)->default('installed');
            $t->unsignedBigInteger('installed_by')->nullable();
            $t->timestamp('installed_at')->nullable();
            $t->timestamp('uninstalled_at')->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('plugin_events', function (Blueprint $t) {
            $t->id();
            $t->string('plugin_key', 64);
            $t->string('action', 32);
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('from_version', 32)->nullable();
            $t->string('to_version', 32)->nullable();
            $t->string('outcome', 16)->default('ok');
            $t->jsonb('meta')->nullable();
            $t->timestamps();
        });

        $this->app->instance(PluginBackup::class, new PluginBackup('pgsql_rollback_test'));
        $this->setUpHelloPlugin();
    }

    protected function tearDown(): void
    {
        if (DB::getDefaultConnection() === 'pgsql_rollback_test') {
            $this->dropScratch();
            $this->tearDownHelloPlugin();
            DB::purge('pgsql_rollback_test');
        }
        parent::tearDown();
    }

    protected function dropScratch(): void
    {
        DB::statement('drop table if exists core_qa_ref, hello_notes, hello_items, plugin_events, installed_plugins, layers, organizations, migrations cascade');
    }

    public function test_rollback_aborts_before_any_change_when_core_fk_points_at_plugin_table(): void
    {
        $lifecycle = fn () => app(PluginLifecycle::class);
        $lifecycle()->install('hello');
        $org = DB::table('organizations')->insertGetId([]);
        DB::table('hello_items')->insert(['id' => 1, 'organization_id' => $org, 'name' => 'original']);

        // Upgrade fails after its v2 migration ran, leaving a pending real backup.
        $this->addHelloV2Migration();
        $this->setHelloVersion('2.0.0');
        file_put_contents($this->helloMigrations.'/2026_09_09_000000_boom.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void { throw new RuntimeException('boom'); } public function down(): void {} };\n");
        try {
            $lifecycle()->upgrade('hello');
            $this->fail('upgrade should throw');
        } catch (\RuntimeException) {
        }
        @unlink($this->helloMigrations.'/2026_09_09_000000_boom.php');
        $row = InstalledPlugin::query()->where('plugin_key', 'hello')->first();
        $this->assertNotNull($row->meta['pending']['backup']);
        @unlink($row->meta['pending']['backup'].'.keep');

        // QA repro: core table FKs to a plugin table; plugin data edited.
        DB::statement('create table core_qa_ref (id serial primary key, item_id bigint references hello_items(id))');
        DB::table('hello_items')->where('id', 1)->update(['name' => 'edited']);

        $migrationsBefore = DB::table('migrations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $metaBefore = $row->fresh()->meta;

        try {
            $lifecycle()->rollback('hello');
            $this->fail('rollback should refuse');
        } catch (PluginLifecycleException $e) {
            $this->assertStringContainsString('core_qa_ref_item_id_fkey on core_qa_ref -> hello_items', $e->getMessage());
        }

        // Nothing changed: tables, data, migrations rows, batches, status.
        $this->assertTrue(Schema::hasTable('hello_notes'), 'v2 batch was not rolled back');
        $this->assertTrue(Schema::hasTable('hello_items'));
        $this->assertTrue(Schema::hasTable('core_qa_ref'));
        $this->assertSame('edited', DB::table('hello_items')->where('id', 1)->value('name'));
        $this->assertSame($migrationsBefore, DB::table('migrations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $fresh = InstalledPlugin::query()->where('plugin_key', 'hello')->first();
        $this->assertSame($metaBefore, $fresh->meta);
        $this->assertSame(InstalledPlugin::STATUS_FAILED, $fresh->status);

        @unlink($fresh->meta['pending']['backup']);
    }
}
