<?php

namespace Tests\Feature\Plugins;

use App\Plugins\PluginBackup;
use App\Plugins\PluginLifecycleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Throwable;

/**
 * Real pg_dump / pg_restore against PostgreSQL. Skipped unless a pgsql
 * connection is reachable (set PLUGIN_PGSQL_TEST_DB to a scratch database;
 * optional PLUGIN_PGSQL_TEST_HOST/PORT/USER/PASSWORD)
 * and pg_dump is on PATH.
 */
class PluginBackupPgsqlTest extends TestCase
{
    protected string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $database = env('PLUGIN_PGSQL_TEST_DB');
        if (! $database) {
            $this->markTestSkipped('PLUGIN_PGSQL_TEST_DB not set.');
        }
        config(['database.connections.pgsql_backup_test' => array_merge(config('database.connections.pgsql'), [
            'url' => null, 'database' => $database,
            'host' => env('PLUGIN_PGSQL_TEST_HOST', config('database.connections.pgsql.host')),
            'port' => env('PLUGIN_PGSQL_TEST_PORT', config('database.connections.pgsql.port')),
            'username' => env('PLUGIN_PGSQL_TEST_USER', config('database.connections.pgsql.username')),
            'password' => env('PLUGIN_PGSQL_TEST_PASSWORD', config('database.connections.pgsql.password')),
        ])]);
        try {
            DB::connection('pgsql_backup_test')->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available: '.$e->getMessage());
        }
        if (! Process::run(['pg_dump', '--version'])->successful()) {
            $this->markTestSkipped('pg_dump not on PATH.');
        }

        $this->prefix = 'zbk'.random_int(1000, 9999).'_';
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            DB::connection('pgsql_backup_test')->statement("drop table if exists {$this->prefix}extra, {$this->prefix}items cascade");
            DB::purge('pgsql_backup_test');
        }
        parent::tearDown();
    }

    protected function backup(): PluginBackup
    {
        return new PluginBackup('pgsql_backup_test');
    }

    public function test_first_install_with_no_tables_skips_dump_and_leaves_no_file(): void
    {
        $result = $this->backup()->dump('zbk', $this->prefix, 'install');

        $this->assertSame(['file' => null, 'skipped' => 'no-tables'], $result);
        $this->assertSame([], glob(storage_path('app/plugin-backups/zbk/*-install.dump')) ?: []);
    }

    public function test_dump_and_restore_round_trip(): void
    {
        $db = DB::connection('pgsql_backup_test');
        $db->statement("create table {$this->prefix}items (id int primary key, name text)");
        $db->table("{$this->prefix}items")->insert(['id' => 1, 'name' => 'kept']);

        $result = $this->backup()->dump('zbk', $this->prefix, 'upgrade');
        $this->assertNull($result['skipped']);
        $this->assertFileExists($result['file']);
        $this->assertGreaterThan(0, filesize($result['file']));

        $db->table("{$this->prefix}items")->delete();
        $this->assertTrue($this->backup()->restore($result['file']));
        $this->assertSame('kept', $db->table("{$this->prefix}items")->value('name'));

        @unlink($result['file']);
    }

    public function test_restore_of_missing_file_aborts(): void
    {
        $this->expectException(PluginLifecycleException::class);
        $this->backup()->restore('/nonexistent/x.dump');
    }

    public function test_replace_and_restore_replays_real_dump_with_rows_and_drops_new_tables(): void
    {
        $db = DB::connection('pgsql_backup_test');
        $items = "{$this->prefix}items";
        $extra = "{$this->prefix}extra";
        $db->statement("create table {$items} (id int primary key, name text)");
        $db->table($items)->insert([['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => "it's \\ tab\t"]]);

        $dump = $this->backup()->dump('zbk', $this->prefix, 'upgrade')['file'];
        $this->assertSame([$items], $this->backup()->dumpTables($dump));

        // The failed batch: data changed and a new plugin table with an FK to a dumped one.
        $db->table($items)->delete();
        $db->table($items)->insert(['id' => 9, 'name' => 'changed']);
        $db->statement("create table {$extra} (id int primary key, item_id int references {$items}(id))");
        $pathBefore = $db->selectOne('show search_path')->search_path;

        $this->assertTrue($this->backup()->replaceAndRestore($this->prefix, $dump));

        $this->assertSame(
            [[1, 'one'], [2, "it's \\ tab\t"]],
            $db->table($items)->orderBy('id')->get()->map(fn ($r) => [(int) $r->id, $r->name])->all(),
        );
        $this->assertFalse((bool) $db->selectOne('select to_regclass(?) as r', [$extra])->r, 'table not in dump dropped');
        // PHP's connection is unaffected (search_path, usable for follow-up writes).
        $this->assertSame($pathBefore, $db->selectOne('show search_path')->search_path);
        $db->table($items)->insert(['id' => 3, 'name' => 'after']);
        $this->assertSame(3, $db->table($items)->count());

        @unlink($dump);
    }

    public function test_failed_replay_rolls_back_everything(): void
    {
        $db = DB::connection('pgsql_backup_test');
        $items = "{$this->prefix}items";
        $extra = "{$this->prefix}extra";
        $db->statement("create table {$items} (id int primary key)");
        $db->table($items)->insert(['id' => 1]);
        $dump = $this->backup()->dump('zbk', $this->prefix, 'upgrade')['file'];
        $db->statement("create table {$extra} (id int)");

        // A core (non-prefixed) object depending on a plugin table makes the
        // replay's DROP fail mid-script; the whole run must roll back.
        $view = 'corev'.random_int(1000, 9999);
        $db->statement("create view {$view} as select id from {$items}");
        try {
            $this->backup()->replaceAndRestore($this->prefix, $dump);
            $this->fail('should throw');
        } catch (PluginLifecycleException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        } finally {
            $db->statement("drop view if exists {$view}");
        }
        $this->assertTrue((bool) $db->selectOne('select to_regclass(?) as r', [$extra])->r, 'extra drop rolled back');
        $this->assertSame(1, $db->table($items)->count());
        @unlink($dump);
    }
}
