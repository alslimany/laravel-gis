<?php

namespace Tests\Arch;

use App\Plugins\PluginManager;
use App\Plugins\PluginServiceProvider;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * ADR-0001 §4/§12 architecture rules, enforced by a best-effort static file
 * scan (regex over source). It catches honest mistakes, not determined
 * evasion (dynamic table names, raw SQL); it is not a security boundary.
 */
class PluginArchitectureTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>> plugin dir => manifest
     */
    protected function plugins(): array
    {
        $plugins = [];
        foreach (File::directories(base_path('packages')) as $dir) {
            $composer = json_decode((string) File::get($dir.'/composer.json'), true);
            $plugins[$dir] = $composer;
        }

        return $plugins;
    }

    /**
     * @return list<SplFileInfo>
     */
    protected function phpFiles(string $dir): array
    {
        return array_values(array_filter(
            File::allFiles($dir),
            fn (SplFileInfo $f) => $f->getExtension() === 'php' && ! str_contains($f->getPathname(), '/vendor/'),
        ));
    }

    public function test_core_does_not_depend_on_plugin_namespaces(): void
    {
        $offenders = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'database'] as $dir) {
            foreach ($this->phpFiles(base_path($dir)) as $file) {
                if (str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    continue;
                }
                if (preg_match('/\bLumina\\\\[A-Z]/', $file->getContents())) {
                    $offenders[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Core must not reference Lumina\\* plugin classes.');
    }

    public function test_every_plugin_has_a_valid_manifest_and_provider(): void
    {
        $this->assertNotEmpty($this->plugins());

        foreach ($this->plugins() as $dir => $composer) {
            $manifest = $composer['extra']['lumina'] ?? null;
            $this->assertIsArray($manifest, "{$dir}: missing extra.lumina");
            $this->assertNotEmpty($composer['version'] ?? null, "{$dir}: composer version (single source of truth)");
            $this->assertArrayNotHasKey('version', $manifest, "{$dir}: duplicate extra.lumina.version; use composer version");
            foreach (['key', 'name', 'core', 'provider'] as $field) {
                $this->assertNotEmpty($manifest[$field] ?? null, "{$dir}: manifest.{$field}");
            }
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $manifest['key']);
            $this->assertNotSame(PluginManager::RESERVED_KEY, $manifest['key'], "{$dir}: key 'core' is reserved");
            $this->assertTrue(is_subclass_of($manifest['provider'], PluginServiceProvider::class), "{$dir}: provider must extend core PluginServiceProvider");
            $this->assertSame($manifest['key'], (new $manifest['provider']($this->app))->key(), "{$dir}: provider key");
            $this->assertStringStartsWith('Lumina\\', $manifest['provider']);
        }
    }

    public function test_plugin_migrations_only_touch_plugin_tables(): void
    {
        foreach ($this->plugins() as $dir => $composer) {
            $manifest = $composer['extra']['lumina'] ?? [];
            $this->assertSame([], PluginManager::validateManifest($manifest), "{$dir}: manifest data.prefix/tables");
            $prefix = $manifest['data']['prefix'];

            if (! is_dir($dir.'/database/migrations')) {
                continue;
            }
            foreach ($this->phpFiles($dir.'/database/migrations') as $file) {
                // Known bypass (ADR-0001 rev 4 §12): only literal Schema::create/table/
                // drop/rename names are checked. DB::statement / raw SQL (or a
                // variable table name) can touch core tables unseen; CR must review it.
                // CREATE/DROP VIEW with a literal prefixed name is allowed (e.g. SQL views for GeoServer).
                preg_match_all("/Schema::(?:create|table|drop|dropIfExists|rename)\\(\\s*'([^']+)'/", $file->getContents(), $m);
                preg_match_all('/\b(?:CREATE|DROP)\s+(?:OR\s+REPLACE\s+)?VIEW\s+(?:IF\s+EXISTS\s+)?([a-z][a-z0-9_]*)/i', $file->getContents(), $views);
                $names = array_values(array_unique(array_merge($m[1], $views[1])));
                $this->assertNotEmpty($names, $file->getFilename().': no literal table/view name found');
                foreach ($names as $table) {
                    $this->assertStringStartsWith($prefix, $table, $file->getFilename()." touches non-plugin table {$table}");
                }
                $this->assertDoesNotMatchRegularExpression('/Schema::table\(\s*\$/', $file->getContents(), $file->getFilename().': dynamic Schema::table');
            }
        }
    }

    public function test_plugin_providers_do_not_auto_load_migrations(): void
    {
        foreach ($this->plugins() as $dir => $composer) {
            foreach ($this->phpFiles($dir.'/src') as $file) {
                $this->assertStringNotContainsString('loadMigrationsFrom', $file->getContents(), $file->getRelativePathname());
            }
        }
    }

    public function test_no_raw_http_in_plugins(): void
    {
        $banned = '/\b(curl_init|curl_exec|fsockopen|stream_socket_client)\s*\(|GuzzleHttp\\\\|file_get_contents\(\s*[\'"]https?:/';
        foreach ($this->plugins() as $dir => $composer) {
            foreach ($this->phpFiles($dir.'/src') as $file) {
                $this->assertDoesNotMatchRegularExpression($banned, $file->getContents(), $file->getRelativePathname().' uses raw HTTP; use the Http facade');
            }
        }
    }

    public function test_scheduled_tasks_only_declared_in_provider_schedule(): void
    {
        foreach ($this->plugins() as $dir => $composer) {
            foreach ($this->phpFiles($dir.'/src') as $file) {
                $isProvider = str_contains($file->getRelativePathname(), 'Providers/');
                if (! $isProvider) {
                    $this->assertDoesNotMatchRegularExpression('/\bSchedule\b/', $file->getContents(), $file->getRelativePathname().' declares scheduling outside the provider');
                }
            }
        }
    }

    public function test_plugin_jobs_declare_global_or_org_scope_marker(): void
    {
        foreach ($this->plugins() as $dir => $composer) {
            $jobsDir = $dir.'/src/Jobs';
            if (! is_dir($jobsDir)) {
                continue;
            }
            foreach ($this->phpFiles($jobsDir) as $file) {
                $src = $file->getContents();
                if (! preg_match('/\bclass\s+\w+/', $src)) {
                    continue;
                }
                $ok = str_contains($src, 'GlobalJob') || str_contains($src, 'OrganizationScopedJob');
                $this->assertTrue($ok, $file->getRelativePathname().' must implement GlobalJob or OrganizationScopedJob (ADR D14)');
            }
        }
    }

    public function test_core_plugin_base_keeps_boot_final(): void
    {
        $boot = new \ReflectionMethod(PluginServiceProvider::class, 'boot');
        $migrations = new \ReflectionMethod(PluginServiceProvider::class, 'migrationsPath');

        $this->assertTrue($boot->isFinal());
        $this->assertTrue($migrations->isStatic());
    }
}
