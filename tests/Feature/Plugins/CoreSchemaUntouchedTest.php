<?php

namespace Tests\Feature\Plugins;

use App\Plugins\PluginLifecycle;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Plugins\Concerns\UsesHelloPlugin;
use Tests\TestCase;

/**
 * ADR-0001 §4/§12: installing plugins never changes core columns or indexes.
 */
class CoreSchemaUntouchedTest extends TestCase
{
    use RefreshDatabase, UsesHelloPlugin;

    protected function tearDown(): void
    {
        if (isset($this->helloMigrations)) {
            $this->tearDownHelloPlugin();
        }
        parent::tearDown();
    }

    /**
     * @param  list<string>  $ignorePrefixes
     * @return array<string, array<string, mixed>>
     */
    protected function snapshot(array $ignorePrefixes): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach ($ignorePrefixes as $prefix) {
                if (str_starts_with($table, $prefix)) {
                    continue 2;
                }
            }
            $snapshot[$table] = [
                'columns' => collect(Schema::getColumns($table))
                    ->map(fn ($c) => [$c['name'], $c['type'], $c['nullable'], $c['default']])->all(),
                'indexes' => collect(Schema::getIndexes($table))
                    ->map(fn ($i) => [$i['name'], $i['columns'], $i['unique']])->sortBy(0)->values()->all(),
            ];
        }
        ksort($snapshot);

        return $snapshot;
    }

    public function test_installing_hello_plugin_leaves_core_schema_untouched(): void
    {
        $this->setUpHelloPlugin();
        $before = $this->snapshot(['hello_']);

        app(PluginLifecycle::class)->install('hello');

        $this->assertTrue(Schema::hasTable('hello_items'));
        $this->assertSame($before, $this->snapshot(['hello_']));
    }

    public function test_installing_early_warning_leaves_core_schema_untouched(): void
    {
        $this->assertNotNull(app(PluginManager::class)->manifest('early-warning'));
        $before = $this->snapshot(['ew_']);

        app(PluginLifecycle::class)->install('early-warning');

        $this->assertTrue(Schema::hasTable('ew_basins'));
        $this->assertSame($before, $this->snapshot(['ew_']));

        app(PluginLifecycle::class)->uninstall('early-warning');
        app(PluginLifecycle::class)->purge('early-warning');
        $this->assertFalse(Schema::hasTable('ew_basins'));
        $this->assertSame($before, $this->snapshot(['ew_']));
    }
}
