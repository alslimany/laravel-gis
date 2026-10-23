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
 * connection is reachable (set PLUGIN_PGSQL_TEST_DB to a scratch database)
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
            DB::connection('pgsql_backup_test')->statement("drop table if exists {$this->prefix}items");
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
}
