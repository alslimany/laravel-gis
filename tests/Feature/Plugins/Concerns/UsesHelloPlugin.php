<?php

namespace Tests\Feature\Plugins\Concerns;

use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Plugins\Hello\HelloServiceProvider;

/**
 * Registers the hello-plugin fixture via a temp installed.json and a temp
 * migrations directory that tests can add v2 migrations to.
 */
trait UsesHelloPlugin
{
    protected string $helloMigrations;

    protected string $helloInstalledJson;

    protected function setUpHelloPlugin(string $version = '1.0.0'): void
    {
        $this->helloMigrations = sys_get_temp_dir().'/hello-mig-'.uniqid();
        File::ensureDirectoryExists($this->helloMigrations);
        File::copyDirectory(base_path('tests/Fixtures/Plugins/Hello/migrations/v1'), $this->helloMigrations);

        HelloServiceProvider::$migrations = $this->helloMigrations;
        HelloServiceProvider::$calls = [];
        HelloServiceProvider::$failOnInstall = false;

        $this->setHelloVersion($version);
    }

    protected function setHelloVersion(string $version): void
    {
        $this->helloInstalledJson = sys_get_temp_dir().'/hello-installed-'.uniqid().'.json';
        file_put_contents($this->helloInstalledJson, json_encode(['packages' => [[
            'name' => 'lumina/hello',
            'extra' => ['lumina' => [
                'key' => 'hello',
                'name' => 'Hello',
                'version' => $version,
                'core' => '^1.0',
                'provider' => HelloServiceProvider::class,
                'data' => ['tables' => ['hello_items', 'hello_notes']],
            ]],
        ]]]));

        $this->app->forgetInstance(PluginManager::class);
        $this->app->instance(PluginManager::class, new PluginManager($this->helloInstalledJson));
    }

    protected function addHelloV2Migration(): void
    {
        File::copyDirectory(base_path('tests/Fixtures/Plugins/Hello/migrations/v2'), $this->helloMigrations);
    }

    protected function tearDownHelloPlugin(): void
    {
        File::deleteDirectory($this->helloMigrations);
        @unlink($this->helloInstalledJson);
        HelloServiceProvider::$migrations = null;
    }
}
